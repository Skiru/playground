#!/usr/bin/env python3
"""Bounded Overture Places bridge. Emits normalized NDJSON only."""

import argparse
import datetime
import json
import resource
import subprocess
import sys
import threading
import time
import urllib.request
from urllib.parse import urljoin, urlparse

STAC_URL = "https://stac.overturemaps.org/catalog.json"
ALLOWED_HOST = "stac.overturemaps.org"
RELEASE_PATTERN = __import__("re").compile(r"^20\d{2}-\d{2}-\d{2}\.\d+$")
MAX_STDERR_BYTES = 8192
MAX_OUTPUT_BYTES = 8 * 1024 * 1024
MAX_ADDRESS_SPACE_BYTES = 2 * 1024 * 1024 * 1024


def latest_release() -> str:
    catalog = release_catalog()
    release = catalog.get("latest")
    if not isinstance(release, str) or not RELEASE_PATTERN.fullmatch(release):
        raise RuntimeError("STAC catalog has no valid latest release")
    return release


def release_catalog() -> dict:
    request = urllib.request.Request(STAC_URL, headers={"User-Agent": "FamilyPlaces-place-discovery/1"})
    with urllib.request.urlopen(request, timeout=10) as response:  # nosec B310: fixed allowlisted HTTPS URL
        catalog = json.load(response)
    if not isinstance(catalog, dict):
        raise RuntimeError("STAC catalog response is invalid")
    return catalog


def validate_release(release: str) -> None:
    if not RELEASE_PATTERN.fullmatch(release):
        raise RuntimeError("invalid Overture release")
    catalog = release_catalog()
    for link in catalog.get("links", []):
        if not isinstance(link, dict) or link.get("rel") != "child":
            continue
        href = urljoin(STAC_URL, str(link.get("href", "")))
        parsed = urlparse(href)
        if parsed.scheme != "https" or parsed.hostname != ALLOWED_HOST:
            raise RuntimeError("STAC release host is not allowlisted")
        if f"/{release}/" in parsed.path:
            return
    raise RuntimeError("requested Overture release is unavailable or expired")


def primary_name(properties: dict) -> str | None:
    names = properties.get("names") or {}
    primary = names.get("primary") if isinstance(names, dict) else None
    return primary if isinstance(primary, str) else None


def normalize_feature(feature: dict, release: str) -> dict:
    if not isinstance(feature, dict):
        raise TypeError("place record is not an object")
    properties = feature.get("properties") or feature
    if not isinstance(properties, dict):
        raise TypeError("place properties are not an object")
    geometry = feature.get("geometry") or properties.get("geometry") or {}
    if not isinstance(geometry, dict):
        raise TypeError("place geometry is not an object")
    coordinates = geometry.get("coordinates") or []
    if not isinstance(coordinates, (list, tuple)) or len(coordinates) < 2 or not all(isinstance(value, (int, float)) for value in coordinates[:2]):
        raise ValueError("place geometry is not a point")
    addresses = properties.get("addresses") or []
    address = addresses[0] if addresses and isinstance(addresses[0], dict) else {}
    taxonomy = properties.get("taxonomy")
    if taxonomy is not None and not isinstance(taxonomy, dict):
        raise TypeError("taxonomy is not an object")
    if taxonomy is None:
        taxonomy = {}
    basic_category = properties.get("basic_category")
    if basic_category is not None and not isinstance(basic_category, str):
        raise TypeError("basic_category is not a string")
    websites = properties.get("websites") or []
    phones = properties.get("phones") or []
    return {
        "id": feature.get("id") or properties.get("id"),
        "version": properties.get("version"),
        "release": release,
        "name": primary_name(properties),
        "latitude": coordinates[1],
        "longitude": coordinates[0],
        "address": {
            "line1": address.get("freeform"),
            "postcode": address.get("postcode"),
            "locality": address.get("locality"),
            "country": address.get("country"),
        },
        "website": websites[0] if websites else None,
        "phone": phones[0] if phones else None,
        "basic_category": basic_category,
        "taxonomy": taxonomy,
        "confidence": properties.get("confidence"),
        "operating_status": properties.get("operating_status"),
        "sources": normalize_sources(properties.get("sources")),
    }


def normalize_sources(raw_sources: object) -> list[dict]:
    if raw_sources is None:
        return []
    if not isinstance(raw_sources, list):
        raise TypeError("sources is not an array")
    result = []
    for source in raw_sources[:32]:
        if not isinstance(source, dict):
            raise TypeError("source provenance is not an object")
        if "property" not in source or "dataset" not in source:
            raise ValueError("source provenance lacks required property or dataset")
        property_path = source["property"]
        dataset = source["dataset"]
        license_id = source.get("license")
        if not isinstance(property_path, str) or not isinstance(dataset, str) or not dataset.strip():
            raise TypeError("source provenance property or dataset has an incompatible type")
        if license_id is not None and (not isinstance(license_id, str) or not license_id.strip()):
            raise TypeError("source provenance license has an incompatible type")
        optional_strings = {}
        for key in ("record_id", "provider", "resource", "version"):
            value = source.get(key)
            if value is not None and not isinstance(value, str):
                raise TypeError(f"source provenance {key} has an incompatible type")
            optional_strings[key] = value[:255] if value is not None else None
        optional_strings["update_time"] = normalize_update_time(source.get("update_time"))
        confidence = source.get("confidence")
        if confidence is not None and (not isinstance(confidence, (int, float)) or isinstance(confidence, bool) or not 0 <= confidence <= 1):
            raise TypeError("source provenance confidence has an incompatible type")
        result.append({
            "property": property_path[:255],
            "dataset": dataset[:255],
            "license": license_id[:255] if license_id is not None else None,
            **optional_strings,
            "confidence": float(confidence) if confidence is not None else None,
        })
    return result


def normalize_update_time(value: object) -> str | None:
    if value is None:
        return None
    if isinstance(value, str):
        if len(value) > 255:
            raise ValueError("source provenance update_time exceeds 255 bytes")
        if "T" not in value:
            raise ValueError("source provenance update_time is not ISO/RFC-3339")
        try:
            parsed = datetime.datetime.fromisoformat(value[:-1] + "+00:00" if value.endswith("Z") else value)
        except ValueError as exception:
            raise ValueError("source provenance update_time is not ISO/RFC-3339") from exception
    elif isinstance(value, datetime.datetime):
        parsed = value
    else:
        raise TypeError("source provenance update_time has an incompatible type")

    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=datetime.timezone.utc)
    normalized = parsed.astimezone(datetime.timezone.utc).isoformat().replace("+00:00", "Z")
    if len(normalized) > 255:
        raise ValueError("source provenance update_time exceeds 255 bytes")
    return normalized


def stream(bbox: str, release: str, limit: int) -> None:
    validate_release(release)
    if release != latest_release():
        stream_historical(bbox, release, limit)
        return
    command = ["overturemaps", "download", "--bbox", bbox, "-f", "geojsonseq", "--type", "place", "--connect_timeout", "5", "--request_timeout", "20"]
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1)  # nosec B603: fixed executable and validated arguments
    assert process.stdout is not None
    assert process.stderr is not None
    emitted = 0
    malformed = 0
    output_bytes = 0
    stderr_chunks: list[str] = []
    stderr_size = 0

    def drain_stderr() -> None:
        nonlocal stderr_size
        for chunk in iter(lambda: process.stderr.read(4096), ""):
            if stderr_size >= MAX_STDERR_BYTES:
                continue
            kept = chunk[: MAX_STDERR_BYTES - stderr_size]
            stderr_chunks.append(kept)
            stderr_size += len(kept.encode("utf-8", errors="replace"))

    stderr_thread = threading.Thread(target=drain_stderr, name="overture-stderr", daemon=True)
    stderr_thread.start()
    try:
        for line in process.stdout:
            output_bytes += len(line.encode("utf-8", errors="replace"))
            if output_bytes > MAX_OUTPUT_BYTES:
                raise RuntimeError("provider output exceeded 8 MiB")
            if not line.strip():
                continue
            try:
                feature = json.loads(line.lstrip("\x1e"))
                record = normalize_feature(feature, release)
            except (json.JSONDecodeError, KeyError, TypeError, ValueError) as exception:
                malformed += 1
                print(f"invalid provider record skipped: {str(exception)[:200]}", file=sys.stderr)
                if malformed > 20:
                    raise RuntimeError("provider schema gate exceeded malformed-record limit") from exception
                continue
            if not record["id"] or not record["name"]:
                continue
            print(json.dumps(record, ensure_ascii=False, separators=(",", ":")), flush=True)
            emitted += 1
            if emitted >= limit:
                process.terminate()
                break
        return_code = process.wait(timeout=5)
        stderr_thread.join(timeout=1)
        if return_code not in (0, -15):
            raise RuntimeError("".join(stderr_chunks)[:1000] or "overturemaps failed")
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()
        stderr_thread.join(timeout=1)


def stream_historical(bbox: str, release: str, limit: int) -> None:
    import pyarrow.dataset as dataset
    import pyarrow.fs as fs
    from shapely import from_wkb
    from shapely.geometry import mapping

    west, south, east, north = (float(value) for value in bbox.split(","))
    source = fs.S3FileSystem(anonymous=True, region="us-west-2", connect_timeout=5, request_timeout=20)
    path = f"overturemaps-us-west-2/release/{release}/theme=places/type=place"
    places = dataset.dataset(path, filesystem=source, format="parquet", partitioning="hive")
    required = {"id", "geometry", "bbox", "names"}
    if not required.issubset(places.schema.names):
        raise RuntimeError("retained release schema lacks required place identity, geometry, or name fields")
    allowed = [name for name in ("id", "geometry", "names", "addresses", "websites", "phones", "basic_category", "taxonomy", "categories", "confidence", "operating_status", "sources", "version") if name in places.schema.names]
    spatial_filter = (
        (dataset.field(("bbox", "xmin")) <= east)
        & (dataset.field(("bbox", "xmax")) >= west)
        & (dataset.field(("bbox", "ymin")) <= north)
        & (dataset.field(("bbox", "ymax")) >= south)
    )
    scanner = places.scanner(columns=allowed, filter=spatial_filter, batch_size=min(128, limit), batch_readahead=1, fragment_readahead=1, use_threads=False, cache_metadata=False)
    stream_historical_scanner(scanner, release, limit, lambda value: mapping(from_wkb(value)))


def stream_historical_scanner(scanner: object, release: str, limit: int, geometry_decoder) -> None:
    emitted = 0
    malformed = 0
    output_bytes = 0
    deadline = time.monotonic() + 110
    for batch in scanner.to_batches():
        if time.monotonic() > deadline:
            raise TimeoutError("historical Overture scan exceeded total timeout")
        for row in batch.to_pylist():
            try:
                geometry = geometry_decoder(row.pop("geometry"))
                feature = {"id": row.pop("id"), "geometry": geometry, "properties": row}
                record = normalize_feature(feature, release)
            except (KeyError, TypeError, ValueError) as exception:
                malformed += 1
                print(f"invalid provider record skipped: {str(exception)[:200]}", file=sys.stderr)
                if malformed > 20:
                    raise RuntimeError("provider schema gate exceeded malformed-record limit") from exception
                continue
            if not record["id"] or not record["name"]:
                continue
            encoded = json.dumps(record, ensure_ascii=False, separators=(",", ":"))
            output_bytes += len(encoded.encode("utf-8")) + 1
            if output_bytes > MAX_OUTPUT_BYTES:
                raise RuntimeError("provider output exceeded 8 MiB")
            print(encoded, flush=True)
            emitted += 1
            if emitted >= limit:
                return


def main() -> int:
    resource.setrlimit(resource.RLIMIT_AS, (MAX_ADDRESS_SPACE_BYTES, MAX_ADDRESS_SPACE_BYTES))
    resource.setrlimit(resource.RLIMIT_CPU, (115, 115))
    parser = argparse.ArgumentParser()
    parser.add_argument("--latest", action="store_true")
    parser.add_argument("--check-release")
    parser.add_argument("--bbox")
    parser.add_argument("--release")
    parser.add_argument("--limit", type=int, default=100)
    args = parser.parse_args()
    if args.latest:
        print(latest_release())
        return 0
    if args.check_release:
        validate_release(args.check_release)
        print(args.check_release)
        return 0
    if not args.bbox or not args.release or not 1 <= args.limit <= 1000:
        parser.error("--bbox, --release and a limit from 1 to 1000 are required")
    parts = args.bbox.split(",")
    if len(parts) != 4 or any(not -180 <= float(value) <= 180 for value in parts):
        parser.error("invalid bounding box")
    stream(args.bbox, args.release, args.limit)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exception:
        print(str(exception)[:1000], file=sys.stderr)
        raise SystemExit(2)
