#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.production}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "error: ENV_FILE '$ENV_FILE' does not exist." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.oracle.arm64.yaml}"
COMPOSE_CMD=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

echo "==> Running OCI Healthchecks..."

# 1. Database check
echo "[1/5] Checking Database health..."
if ! "${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database pg_isready -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" >/dev/null 2>&1; then
  echo "ERROR: PostgreSQL is not ready." >&2
  exit 1
fi

postgis_ver=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc "SELECT PostGIS_Version();" 2>/dev/null || true)
if [[ ! "$postgis_ver" =~ ^3\. ]]; then
  echo "ERROR: PostGIS version check failed (got '$postgis_ver')." >&2
  exit 1
fi
echo "  - Database & PostGIS OK ($postgis_ver)"

# 2. API internal health check
echo "[2/5] Checking API container health..."
if ! "${COMPOSE_CMD[@]}" exec -T api curl --fail --silent "http://localhost/api/v1/health/live" >/dev/null 2>&1; then
  echo "ERROR: API container liveness probe failed." >&2
  exit 1
fi
echo "  - API container liveness OK"

# 3. Web internal health check
echo "[3/5] Checking Web container health..."
if ! "${COMPOSE_CMD[@]}" exec -T web node -e "fetch('http://localhost:3000/').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))" >/dev/null 2>&1; then
  echo "ERROR: Web container liveness probe failed." >&2
  exit 1
fi
echo "  - Web container liveness OK"

# 4. Gateway (Caddy) process and config check
echo "[4/5] Checking Gateway (Caddy) process and configuration..."
if ! "${COMPOSE_CMD[@]}" exec -T gateway caddy version >/dev/null 2>&1; then
  echo "ERROR: Gateway (Caddy) is not running or responsive." >&2
  exit 1
fi
echo "  - Gateway process OK"

# 5. Gateway reverse proxy routing & public smoke test
echo "[5/5] Checking Gateway reverse proxy endpoints..."
domain="${DOMAIN_NAME:-playground.com.pl}"
if ! "${COMPOSE_CMD[@]}" exec -T gateway wget --header="Host: ${domain}" -qO- "http://localhost:80/api/v1/health/live" 2>/dev/null | grep -q .; then
  echo "ERROR: Gateway routing to API failed." >&2
  exit 1
fi

if ! "${COMPOSE_CMD[@]}" exec -T gateway wget --header="Host: ${domain}" -qO- "http://localhost:80/" >/dev/null 2>&1; then
  echo "ERROR: Gateway routing to Web root failed." >&2
  exit 1
fi

echo "  - Gateway routing to API and Web OK"

if [[ -n "${DOMAIN_NAME:-}" && "${SKIP_EXTERNAL_SMOKE:-false}" != "true" ]]; then
  echo "  - Testing public HTTP/HTTPS endpoint for https://${DOMAIN_NAME}..."
  if curl --fail --silent --insecure "https://${DOMAIN_NAME}/api/v1/health/live" >/dev/null 2>&1; then
    echo "  - Public endpoint https://${DOMAIN_NAME}/api/v1/health/live OK"
  else
    echo "  - Notice: Public endpoint test failed or DNS not yet pointing to this host."
  fi
fi

echo "==> All OCI Healthchecks PASSED."
exit 0
