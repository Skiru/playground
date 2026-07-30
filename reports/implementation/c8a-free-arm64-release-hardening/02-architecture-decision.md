# Architecture decision

Application images are published as amd64/arm64 OCI manifests. Worker reuses the API image. The existing amd64 upstream `postgis/postgis:18-3.6` stays unchanged; the ARM overlay replaces only the database with first-party `ghcr.io/skiru/family-places-postgis` derived from official PostgreSQL 18 and signed PGDG PostGIS 3.6 packages. PGDG arm64 package resolution succeeded for 3.6.4.
