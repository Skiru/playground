#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

VALIDATE_ONLY=false
if [[ "${1:-}" == "--validate-only" ]]; then
  VALIDATE_ONLY=true
  shift
fi

TARGET_DESCRIPTOR="${1:-}"
ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.production}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: Environment file '$ENV_FILE' not found." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

LOCK_DIR="${DEPLOY_LOCK_DIR:-${ROOT_DIR}/.production}"
RELEASES_DIR="${LOCK_DIR}/releases"

if [[ -z "$TARGET_DESCRIPTOR" ]]; then
  if [[ -f "${RELEASES_DIR}/current.json" ]]; then
    TARGET_DESCRIPTOR="${RELEASES_DIR}/current.json"
  else
    echo "ERROR: No release descriptor provided and '${RELEASES_DIR}/current.json' does not exist." >&2
    echo "Usage: $0 [--validate-only] <release-descriptor.json>" >&2
    exit 1
  fi
fi

if [[ ! -f "$TARGET_DESCRIPTOR" ]]; then
  echo "ERROR: Release descriptor file '$TARGET_DESCRIPTOR' not found." >&2
  exit 1
fi

echo "==> Validating target release descriptor: $TARGET_DESCRIPTOR"

for key in releaseSha releaseVersion apiImageDigest webImageDigest postgisImageDigest; do
  val=$(jq -r ".${key} // empty" "$TARGET_DESCRIPTOR")
  if [[ -z "$val" ]]; then
    echo "ERROR: Target descriptor missing required key '$key'." >&2
    exit 1
  fi
done

target_sha=$(jq -r .releaseSha "$TARGET_DESCRIPTOR")
target_ver=$(jq -r .releaseVersion "$TARGET_DESCRIPTOR")
target_api=$(jq -r .apiImageDigest "$TARGET_DESCRIPTOR")
target_web=$(jq -r .webImageDigest "$TARGET_DESCRIPTOR")
target_postgis=$(jq -r .postgisImageDigest "$TARGET_DESCRIPTOR")

echo "  - Release SHA: $target_sha"
echo "  - Release Version: $target_ver"
echo "  - API Image: $target_api"
echo "  - Web Image: $target_web"
echo "  - PostGIS Image: $target_postgis"

if [[ "$VALIDATE_ONLY" == "true" ]]; then
  echo "==> Release descriptor is valid. Validation complete."
  exit 0
fi

echo "==> Proceeding with image rollback..."
echo "NOTE: Database schema WILL NOT be rolled back or downgraded."

LOCK_FILE="${LOCK_DIR}/deploy.lock"

acquire_lock() {
  if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
      echo "ERROR: Another deployment/rollback is currently active." >&2
      exit 1
    fi
  else
    local lock_dir="${LOCK_FILE}.dir"
    if ! mkdir "$lock_dir" 2>/dev/null; then
      echo "ERROR: Another deployment/rollback is currently active (lockdir: $lock_dir)." >&2
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

export API_IMAGE="$target_api"
export WEB_IMAGE="$target_web"
export POSTGIS_IMAGE="$target_postgis"

COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.oracle.arm64.yaml}"
COMPOSE_CMD=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

echo "[1/4] Pulling previous release images..."
API_IMAGE="$target_api" WEB_IMAGE="$target_web" POSTGIS_IMAGE="$target_postgis" "${COMPOSE_CMD[@]}" pull

echo "[2/4] Restarting application containers with target images..."
API_IMAGE="$target_api" WEB_IMAGE="$target_web" POSTGIS_IMAGE="$target_postgis" "${COMPOSE_CMD[@]}" up --wait -d database worker api web gateway

echo "[3/4] Running healthchecks..."
ENV_FILE="$ENV_FILE" COMPOSE_FILE="$COMPOSE_FILE" API_IMAGE="$target_api" WEB_IMAGE="$target_web" POSTGIS_IMAGE="$target_postgis" "${SCRIPT_DIR}/healthcheck-oracle.sh"

echo "[4/4] Updating current release pointer..."
cp "$TARGET_DESCRIPTOR" "${RELEASES_DIR}/current.json"

release_lock

echo "==> Image rollback to $target_ver ($target_sha) COMPLETED SUCCESSFULLY!"
exit 0
