# ADR 0005: PostgreSQL and PostGIS

Status: Accepted

## Decision

Use PostgreSQL 18 with PostGIS 3.6 geography points as authoritative storage.
Enable `postgis`, `pg_trgm`, and `unaccent` via migrations.

## Consequences

Radius candidate selection uses indexed `ST_DWithin`; distance is computed only
after candidate selection. Coordinate-order regression tests are mandatory.
