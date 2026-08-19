#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

DRY_RUN=false
if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=true
  echo "==> Running in DRY-RUN mode..."
fi

ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.production}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: Environment file '$ENV_FILE' not found." >&2
  exit 1
fi

if [[ ! -f "${ROOT_DIR}/.env" ]] && [[ -f "${ROOT_DIR}/.env.production" ]]; then
  ln -sf .env.production "${ROOT_DIR}/.env"
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

required_vars=(
  API_IMAGE
  WEB_IMAGE
  POSTGIS_IMAGE
  APP_SECRET
  DATABASE_URL
  POSTGRES_DB
  POSTGRES_USER
  POSTGRES_PASSWORD
  DOMAIN_NAME
  MEDIA_PUBLIC_BASE_URL
  MAP_STYLE_URL
  MAP_ATTRIBUTION
  MAP_PROVIDER_NAME
  RELEASE_SHA
  RELEASE_VERSION
)

echo "[1/19] Validating environment..."
for var in "${required_vars[@]}"; do
  if [[ -z "${!var:-}" ]]; then
    echo "ERROR: Required environment variable '$var' is not set." >&2
    exit 1
  fi
done

for img_var in API_IMAGE WEB_IMAGE POSTGIS_IMAGE; do
  val="${!img_var}"
  if [[ "$val" != *"@sha256:"* ]]; then
    echo "ERROR: $img_var ('$val') must be an immutable GHCR digest containing '@sha256:'." >&2
    exit 1
  fi
done
echo "  - Environment validated OK."

COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.oracle.arm64.yaml}"
COMPOSE_CMD=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

for cmd in docker jq; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: Required CLI command '$cmd' is not installed." >&2
    exit 1
  fi
done

LOCK_DIR="${DEPLOY_LOCK_DIR:-${ROOT_DIR}/.production}"
mkdir -p "$LOCK_DIR"
LOCK_FILE="${LOCK_DIR}/deploy.lock"

acquire_lock() {
  if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
      echo "ERROR: Another deployment is currently active." >&2
      exit 1
    fi
  else
    local lock_dir="${LOCK_FILE}.dir"
    if ! mkdir "$lock_dir" 2>/dev/null; then
      echo "ERROR: Another deployment is currently active (lockdir: $lock_dir)." >&2
      exit 1
    fi
  fi
}

release_lock() {
  if command -v flock >/dev/null 2>&1; then
    flock -u 9 2>/dev/null || true
  else
    rmdir "${LOCK_FILE}.dir" 2>/dev/null || true
  fi
}

acquire_lock
echo "[2/19] Deployment lock acquired."

echo "[3/19] Validating Docker Compose configuration..."
"${COMPOSE_CMD[@]}" config >/dev/null

if [[ "$DRY_RUN" == "true" ]]; then
  echo "==> DRY-RUN: Compose configuration valid. Halting dry run."
  release_lock
  exit 0
fi

echo "[4/19] Pulling immutable release images..."
"${COMPOSE_CMD[@]}" pull

echo "[5/19] Starting PostgreSQL database..."
"${COMPOSE_CMD[@]}" up --wait -d database

echo "[6/19] Waiting for PostgreSQL database health..."
"${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database pg_isready -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}"

echo "[7/19] Recording database migration version before deployment..."
migration_before=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc "SELECT COALESCE(max(version), '') FROM doctrine_migration_versions" 2>/dev/null || echo "")

echo "[8/19] Executing Doctrine database migrations..."
"${COMPOSE_CMD[@]}" run --rm --no-deps api php bin/console doctrine:migrations:migrate --no-interaction

echo "[9/19] Verifying database migration success..."
migration_after=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc "SELECT COALESCE(max(version), '') FROM doctrine_migration_versions" 2>/dev/null || echo "")
echo "  - Migrations: before='$migration_before', after='$migration_after'"

echo "[10/19] Starting API container..."
"${COMPOSE_CMD[@]}" up --wait -d api

echo "[11/19] Verifying API container health..."
"${COMPOSE_CMD[@]}" exec -T api curl --fail --silent http://localhost/api/v1/health/live >/dev/null

echo "[12/19] Starting Web SSR container..."
"${COMPOSE_CMD[@]}" up --wait -d web

echo "[13/19] Verifying Web container health..."
"${COMPOSE_CMD[@]}" exec -T web node -e "fetch('http://localhost:3000/').then(r=>{if(!r.ok)process.exit(1)})" >/dev/null

echo "[14/19] Starting background worker..."
"${COMPOSE_CMD[@]}" up --wait -d worker

echo "[15/19] Starting Gateway (Caddy)..."
"${COMPOSE_CMD[@]}" up --wait -d gateway

echo "[16/19] Verifying Gateway configuration..."
"${COMPOSE_CMD[@]}" exec -T gateway caddy version >/dev/null

echo "[17/19] Executing healthchecks & smoke tests..."
ENV_FILE="$ENV_FILE" COMPOSE_FILE="$COMPOSE_FILE" "${SCRIPT_DIR}/healthcheck-oracle.sh"

echo "[18/19] Recording release metadata..."
RELEASES_DIR="${LOCK_DIR}/releases"
mkdir -p "$RELEASES_DIR"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
RELEASE_FILE="${RELEASES_DIR}/${TIMESTAMP}-${RELEASE_SHA}.json"
CURRENT_FILE="${RELEASES_DIR}/current.json"

rollback_compat="image-only"
if [[ "$migration_before" != "$migration_after" ]]; then
  rollback_compat="requires-explicit-verification"
fi

jq -n \
  --arg sha "$RELEASE_SHA" \
  --arg version "$RELEASE_VERSION" \
  --arg api "$API_IMAGE" \
  --arg web "$WEB_IMAGE" \
  --arg postgis "$POSTGIS_IMAGE" \
  --arg before "$migration_before" \
  --arg after "$migration_after" \
  --arg compat "$rollback_compat" \
  --arg deployed "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  '{
    releaseSha: $sha,
    releaseVersion: $version,
    apiImageDigest: $api,
    webImageDigest: $web,
    postgisImageDigest: $postgis,
    migrationVersionBefore: $before,
    migrationVersionAfter: $after,
    rollbackCompatibility: $compat,
    deploymentTimestamp: $deployed
  }' > "$RELEASE_FILE"

cp "$RELEASE_FILE" "$CURRENT_FILE"
echo "  - Release recorded at $RELEASE_FILE"

echo "[19/19] Releasing deployment lock..."
release_lock

echo "==> Deployment of release $RELEASE_VERSION ($RELEASE_SHA) COMPLETED SUCCESSFULLY!"
exit 0
