# ADR: automated place-discovery provider

- Status: accepted
- Date: 2026-07-28
- Decision owners: FamilyPlaces

## Context

FamilyPlaces needs reviewable suggestions without scraping Google Maps, copying photographs, or making systematic requests to public Nominatim/Overpass services. Provider records must never become public places without an administrator decision.

## Decision

Provider v1 is Overture Maps Places. The provider-neutral `PlaceDiscoveryProvider` port exposes a release lookup and a bounded record stream. Infrastructure resolves the current release through `https://stac.overturemaps.org/catalog.json` and runs the official `overturemaps` Python client 1.0.1 through Symfony Process with an argument array. The helper emits normalized NDJSON; STAC, GeoJSONSeq, Python, S3 and CLI details do not cross the application boundary.

The adapter accepts geometry and limits only. It has fixed hosts, timeouts, a 1,000-record hard ceiling, a 1 MiB per-record buffer ceiling and a 32 KiB stored-snapshot ceiling. Production is disabled unless `PLACE_DISCOVERY_ENABLED=true`.

Candidates are private review data. Approval locks and versions the candidate, invokes the existing `PlaceCommandHandler::create()` path and creates a `DRAFT`; publication remains a separate existing administrator workflow. Fuzzy matching can only produce `POSSIBLE_DUPLICATE`, never a merge.

The current profile uses `basic_category` and `taxonomy` as optional fields. Values are verified against Overture's `overture_categories.csv` where available; profile revisions require a version change and fixture update. Unknown extra fields from providers are discarded.

## Licensing and attribution

Overture Places is multi-license by contributing source and property. The typed adapter retains bounded `sources[]` property JSON Pointer (including the empty whole-feature pointer), required dataset, nullable license, source record id, update time, provider, resource, version and confidence. Declared values can include CDLA Permissive 2.0 and Apache-2.0/Foursquare provenance, but an absent license remains explicitly unresolved rather than being inferred. The official Python client is MIT licensed. Runtime and product attribution state `Overture Maps Foundation` and link to `https://overturemaps.org/`; required notices ship with release artifacts. Missing or unknown licensing blocks approval until an administrator records an explicit reviewed resolution.

No photos, website images, social-media images, Google content or hotlinks are imported. Approved drafts use the existing media workflow and category fallback.

## Consequences

The release check runs daily at 03:17 Europe/Warsaw and creates a bounded run with durable pending-dispatch state. Delivery and outbox advancement use one database transaction; a bounded scheduler, admin and CLI reconciler recovers a crash before delivery, while duplicate delivery remains a no-op after one logical execution. Worker claims increment a database execution token, and every heartbeat/finalization is fenced by run, worker and token. Explicit retry preserves the requested release only after validating retained STAC membership; unavailable releases fail with a typed diagnostic and are never relabelled as retried. A queued delivery consumed while disabled becomes intentionally `CANCELLED` and can be retried explicitly after re-enabling. GERS IDs can churn after matching changes, so identity is authoritative when stable but fuzzy review remains necessary.

Foursquare Open Source Places may be added later behind the same port and source-link constraints. Foursquare is not implemented in v1. Google scraping, paid providers, public Nominatim harvesting and public Overpass harvesting remain prohibited.
