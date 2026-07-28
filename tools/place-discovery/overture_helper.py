#!/usr/bin/env python3
"""Bounded Overture Places bridge. Emits normalized NDJSON only."""

import argparse
import json
import subprocess
import sys
import urllib.request

STAC_URL = "https://stac.overturemaps.org/catalog.json"
ALLOWED_HOST = "stac.overturemaps.org"


def latest_release() -> str:
    request = urllib.request.Request(STAC_URL, headers={"User-Agent": "FamilyPlaces-place-discovery/1"})
    with urllib.request.urlopen(request, timeout=10) as response:  # nosec B310: fixed allowlisted HTTPS URL
        catalog = json.load(response)
    release = catalog.get("latest")
    if not isinstance(release, str):
        raise RuntimeError("STAC catalog has no latest release")
    return release


def primary_name(properties: dict) -> str | None:
    names = properties.get("names") or {}
    primary = names.get("primary") if isinstance(names, dict) else None
    return primary if isinstance(primary, str) else None


def normalize_feature(feature: dict, release: str) -> dict:
    properties = feature.get("properties") or feature
    geometry = feature.get("geometry") or properties.get("geometry") or {}
    coordinates = geometry.get("coordinates") or []
    if len(coordinates) < 2:
        raise ValueError("place geometry is not a point")
    addresses = properties.get("addresses") or []
    address = addresses[0] if addresses and isinstance(addresses[0], dict) else {}
    taxonomy = properties.get("taxonomy")
    if not isinstance(taxonomy, dict):
        raise ValueError("required taxonomy field is absent")
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
        "basic_category": properties.get("basic_category"),
        "taxonomy": taxonomy,
        "confidence": properties.get("confidence"),
        "operating_status": properties.get("operating_status"),
    }


def stream(bbox: str, release: str, limit: int) -> None:
    if release != latest_release():
        raise RuntimeError("official client adapter supports the current STAC release only")
    command = ["overturemaps", "download", "--bbox", bbox, "-f", "geojsonseq", "--type", "place", "--connect_timeout", "5", "--request_timeout", "20"]
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1)  # nosec B603: fixed executable and validated arguments
    assert process.stdout is not None
    emitted = 0
    malformed = 0
    try:
        for line in process.stdout:
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
        if return_code not in (0, -15):
            assert process.stderr is not None
            raise RuntimeError(process.stderr.read(1000) or "overturemaps failed")
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--latest", action="store_true")
    parser.add_argument("--bbox")
    parser.add_argument("--release")
    parser.add_argument("--limit", type=int, default=100)
    args = parser.parse_args()
    if args.latest:
        print(latest_release())
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
