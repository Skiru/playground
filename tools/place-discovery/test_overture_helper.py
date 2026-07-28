import importlib.util
import pathlib
import unittest


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
