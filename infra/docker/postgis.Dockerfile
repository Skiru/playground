FROM postgres:18-bookworm@sha256:1961f96e6029a02c3812d7cb329a3b03a3ac2bb067058dec17b0f5596aca9296

# PGDG provides signed arm64 packages for PostgreSQL 18/PostGIS 3.6; builds never occur on the VM.
ARG POSTGIS_VERSION=3.6
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && install -d -m 0755 /usr/share/keyrings \
    && curl --fail --silent --show-error --location https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends "postgresql-18-postgis-3=${POSTGIS_VERSION}.*" "postgresql-18-postgis-3-scripts=${POSTGIS_VERSION}.*" \
    && apt-get purge -y --auto-remove curl gnupg \
    && rm -rf /var/lib/apt/lists/*

COPY infra/docker/postgis-init/ /docker-entrypoint-initdb.d/
