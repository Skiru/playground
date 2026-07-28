# Overture Maps Places

## Runtime contract

- STAC catalog: `https://stac.overturemaps.org/catalog.json`
- Current implementation baseline: release `2026-07-22.0`, schema `v1.18.0`
- Client: official `overturemaps` Python package `1.0.1`
- Client license: MIT
- Data licenses: property-level provenance in `sources[]` is authoritative. Records may combine CDLA Permissive 2.0, Apache-2.0/Foursquare, and other identifiers declared by the retained release.
- Allowed network hosts: `stac.overturemaps.org` and official storage endpoints selected internally by the pinned client
- Update cadence: release-driven, normally monthly; the schedule checks daily and no-ops when unchanged

The helper validates explicit releases against the official STAC catalog, including retained historical releases, then requests Places for a validated maximum-25-km area and streams GeoJSONSeq into bounded normalized NDJSON. Stored snapshots contain only identity/version, display name, bounded address/contact fields, optional category data, confidence, operating status, and bounded `sources[]` provenance. They never contain arbitrary provider payloads or photos.

## Profile family-v1

Verified taxonomy values mapped conservatively: `playground`, `indoor_playcenter`, `amusement_park`, `water_park`, `zoo`, `aquarium`, `science_museum`, `childrens_museum`, `trampoline_park`, `swimming_pool`, and `park`. FamilyPlaces targets are `bawialnie`, `sport`, `natura`, `muzea`, and `parki`.

Broad `restaurant` and `cafe` records require a normalized word-level match for configured stems such as `dziecko`, `dzieci`, `rodzin`, `maluch`, `bawial`, `kids`, `junior`, `family`, or `play`. A match records a reason; it does not assert child-friendliness and never publishes.

Scoring is deterministic: 20 points for valid identity/name/coordinates, up to 25 for source confidence, 35 for a verified family category, 15 for a family keyword, 3 for a website and 2 for a phone. Permanently closed records become stale review data. Missing mappings become `NEEDS_MAPPING`; fuzzy duplicate signals become `POSSIBLE_DUPLICATE`.

## Schema Evolution
- Taxonomy and basic_category are optional.
- Unknown extras are ignored during normalization.
- Records without ID or name are skipped.

## Limitations

Coverage, names, contact details, categories and GERS continuity vary by source and release. Addresses can be incomplete. Confidence is source quality evidence, not a FamilyPlaces endorsement. No geocoding occurs. Source refresh never mutates an approved public Place; closure sets an admin-review flag. No external photos are copied or hotlinked.

Attribution: `Overture Maps Foundation` plus every retained per-property license/provider identifier. Foursquare-derived properties retain the Foursquare dataset and Apache-2.0 provenance. A record with absent or unknown required provenance remains private review data in `NEEDS_MAPPING`; an administrator must resolve licensing before approval. See repository `NOTICE` and `tools/place-discovery/NOTICE`.
