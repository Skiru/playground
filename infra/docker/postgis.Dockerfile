FROM golang:1.26.5-bookworm@sha256:1ecb7edf62a0408027bd5729dfd6b1b8766e578e8df93995b225dfd0944eb651 AS gosu-builder
RUN CGO_ENABLED=0 go install -trimpath -ldflags '-s -w' github.com/tianon/gosu@6456aaa0f3c854d199d0f037f068eb97515b7513

FROM postgres:18-bookworm@sha256:1961f96e6029a02c3812d7cb329a3b03a3ac2bb067058dec17b0f5596aca9296

COPY --from=gosu-builder /go/bin/gosu /usr/local/bin/gosu

# PGDG provides signed arm64 packages for PostgreSQL 18/PostGIS 3.6; builds never occur on the VM.
ARG POSTGIS_VERSION=3.6
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
# hadolint ignore=DL3008
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
