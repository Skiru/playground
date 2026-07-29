import contextlib
import datetime
import importlib.util
import io
import json
import pathlib
import unittest

import pyarrow


SPEC = importlib.util.spec_from_file_location("overture_helper", pathlib.Path(__file__).with_name("overture_helper.py"))
assert SPEC and SPEC.loader
HELPER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HELPER)


class OvertureHelperSchemaTest(unittest.TestCase):
    def feature(self, **properties):
        base = {
            "id": "gers-1",
            "geometry": {"type": "Point", "coordinates": [21.0, 52.0]},
            "properties": {"names": {"primary": "Family Park"}, **properties},
        }
        return base

    def test_full_taxonomy(self):
        record = HELPER.normalize_feature(self.feature(taxonomy={"hierarchy": ["park"]}), "2026-07-22.0")
        self.assertEqual(["park"], record["taxonomy"]["hierarchy"])

    def test_basic_category_only(self):
        record = HELPER.normalize_feature(self.feature(basic_category="park"), "2026-07-22.0")
        self.assertEqual("park", record["basic_category"])
        self.assertEqual({}, record["taxonomy"])

    def test_no_category_and_unknown_extra_are_valid(self):
        records = [HELPER.normalize_feature(self.feature(unknown_future_field={"safe": True}), "2026-07-22.0") for _ in range(25)]
        self.assertTrue(all(record["basic_category"] is None and record["taxonomy"] == {} for record in records))
        self.assertTrue(all("unknown_future_field" not in record for record in records))

    def test_malformed_taxonomy_type_is_diagnostic(self):
        with self.assertRaisesRegex(TypeError, "taxonomy"):
            HELPER.normalize_feature(self.feature(taxonomy="legacy"), "2026-07-22.0")

    def test_missing_geometry_is_diagnostic(self):
        feature = self.feature()
        feature.pop("geometry")
        with self.assertRaisesRegex(ValueError, "geometry"):
            HELPER.normalize_feature(feature, "2026-07-22.0")

    def test_root_pointer_optional_license_and_bounded_typed_provenance(self):
        sources = [
            {"property": "", "dataset": "Overture", "provider": "Overture Maps Foundation", "resource": "places", "version": "1", "confidence": 0.8},
            {"property": "/names/primary", "dataset": "Foursquare", "license": "Apache-2.0", "record_id": "fsq-1", "update_time": "2026-07-01T00:00:00Z"},
        ]
        record = HELPER.normalize_feature(self.feature(sources=sources), "2026-07-22.0")
        self.assertEqual(["", "/names/primary"], [source["property"] for source in record["sources"]])
        self.assertEqual([None, "Apache-2.0"], [source["license"] for source in record["sources"]])
        self.assertEqual("Overture Maps Foundation", record["sources"][0]["provider"])
        self.assertEqual("places", record["sources"][0]["resource"])
        self.assertEqual("1", record["sources"][0]["version"])
        self.assertEqual(0.8, record["sources"][0]["confidence"])

    def test_missing_optional_license_is_valid_but_malformed_type_fails(self):
        self.assertIsNone(HELPER.normalize_sources([{"property": "", "dataset": "Overture"}])[0]["license"])
        with self.assertRaisesRegex(TypeError, "license"):
            HELPER.normalize_sources([{"property": "", "dataset": "Overture", "license": ["invalid"]}])

    def test_source_count_boundary_is_fail_closed(self):
        sources = [{"property": f"/{index}", "dataset": "Overture"} for index in range(HELPER.MAX_SOURCE_ITEMS)]
        self.assertEqual(HELPER.MAX_SOURCE_ITEMS, len(HELPER.normalize_sources(sources)))
        with self.assertRaisesRegex(ValueError, "32 items"):
            HELPER.normalize_sources([*sources, {"property": "/overflow", "dataset": "Overture"}])

    def test_source_field_byte_boundaries_are_fail_closed_without_slicing(self):
        for field in ("property", "dataset", "record_id", "license"):
            with self.subTest(field=field, boundary="exact"):
                source = {"property": "", "dataset": "Overture", field: "x" * HELPER.MAX_SOURCE_FIELD_BYTES}
                normalized = HELPER.normalize_sources([source])[0]
                self.assertEqual("x" * HELPER.MAX_SOURCE_FIELD_BYTES, normalized[field])
            with self.subTest(field=field, boundary="overflow"):
                source = {"property": "", "dataset": "Overture", field: "x" * (HELPER.MAX_SOURCE_FIELD_BYTES + 1)}
                with self.assertRaisesRegex(ValueError, field):
                    HELPER.normalize_sources([source])

    def test_update_time_normalizes_strings_aware_and_naive_datetimes_and_null(self):
        values = [
            None,
            "2026-07-01T02:30:00+02:00",
            datetime.datetime(2026, 7, 1, 2, 30, tzinfo=datetime.timezone(datetime.timedelta(hours=2))),
            datetime.datetime(2026, 7, 1, 0, 30),
        ]
        normalized = [HELPER.normalize_update_time(value) for value in values]
        self.assertEqual([None, "2026-07-01T00:30:00Z", "2026-07-01T00:30:00Z", "2026-07-01T00:30:00Z"], normalized)

    def test_update_time_rejects_unrelated_types_and_malformed_or_oversized_strings(self):
        for value in (object(), 123, "not-a-timestamp", "2" * 256):
            with self.subTest(value=type(value).__name__):
                with self.assertRaises((TypeError, ValueError)):
                    HELPER.normalize_update_time(value)

    def test_real_pyarrow_nested_timestamp_to_pylist_normalizes_and_serializes(self):
        source_type = pyarrow.struct([
            ("property", pyarrow.string()),
            ("dataset", pyarrow.string()),
            ("license", pyarrow.string()),
            ("record_id", pyarrow.string()),
            ("update_time", pyarrow.timestamp("us", tz="Europe/Warsaw")),
            ("provider", pyarrow.string()),
            ("resource", pyarrow.string()),
            ("version", pyarrow.string()),
            ("confidence", pyarrow.float64()),
        ])
        sources = pyarrow.array([[{
            "property": "/names/primary", "dataset": "Foursquare", "license": None, "record_id": "fsq-1",
            "update_time": datetime.datetime(2026, 7, 1, 2, 30, tzinfo=datetime.timezone(datetime.timedelta(hours=2))),
            "provider": "Foursquare", "resource": "places", "version": "1", "confidence": 0.9,
        }]], type=pyarrow.list_(source_type))
        batch = pyarrow.record_batch([
            pyarrow.array(["gers-arrow"]),
            pyarrow.array([b"point"]),
            pyarrow.array([{"primary": "Arrow Family Park"}]),
            sources,
        ], names=["id", "geometry", "names", "sources"])

        row = batch.to_pylist()[0]
        self.assertIsInstance(row["sources"][0]["update_time"], datetime.datetime)
        feature = {"id": row.pop("id"), "geometry": {"type": "Point", "coordinates": [21.0, 52.0]}, "properties": {key: value for key, value in row.items() if key != "geometry"}}
        record = HELPER.normalize_feature(feature, "2026-06-17.0")

        self.assertEqual("2026-07-01T00:30:00Z", record["sources"][0]["update_time"])
        json.dumps(record)

    def test_bounded_historical_scanner_streams_pyarrow_timestamp_without_s3(self):
        source_type = pyarrow.struct([
            ("property", pyarrow.string()), ("dataset", pyarrow.string()), ("update_time", pyarrow.timestamp("us")),
        ])
        batch = pyarrow.record_batch([
            pyarrow.array(["gers-historical"]),
            pyarrow.array([b"point"]),
            pyarrow.array([{"primary": "Historical Family Park"}]),
            pyarrow.array([[{"property": "", "dataset": "Overture", "update_time": datetime.datetime(2026, 6, 1, 12, 0)}]], type=pyarrow.list_(source_type)),
        ], names=["id", "geometry", "names", "sources"])

        class Scanner:
            def to_batches(self):
                return [batch]

        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            HELPER.stream_historical_scanner(Scanner(), "2026-06-17.0", 1, lambda _: {"type": "Point", "coordinates": [21.0, 52.0]})
        record = json.loads(output.getvalue())
        self.assertEqual("2026-06-01T12:00:00Z", record["sources"][0]["update_time"])

    def test_unknown_release_and_host_are_rejected_from_fixture_catalog(self):
        original = HELPER.release_catalog
        try:
            HELPER.release_catalog = lambda: {"latest": "2026-07-22.0", "links": [{"rel": "child", "href": "https://evil.example/2026-07-22.0/catalog.json"}]}
            with self.assertRaisesRegex(RuntimeError, "allowlisted"):
                HELPER.validate_release("2026-07-22.0")
            HELPER.release_catalog = lambda: {"latest": "2026-07-22.0", "links": []}
            with self.assertRaisesRegex(RuntimeError, "unavailable"):
                HELPER.validate_release("2026-06-17.0")
        finally:
            HELPER.release_catalog = original

    def test_retained_historical_release_is_accepted_from_fixture_catalog(self):
        original = HELPER.release_catalog
        try:
            HELPER.release_catalog = lambda: {"latest": "2026-07-22.0", "links": [{"rel": "child", "href": "./2026-06-17.0/catalog.json"}]}
            HELPER.validate_release("2026-06-17.0")
        finally:
            HELPER.release_catalog = original


if __name__ == "__main__":
    unittest.main()
