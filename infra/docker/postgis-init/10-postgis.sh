#!/bin/sh
set -eu

# The official entrypoint only runs this for a new database.  CREATE EXTENSION
# is idempotent, so operators can also execute it manually for existing volumes.
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set ON_ERROR_STOP=1 <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
SQL
