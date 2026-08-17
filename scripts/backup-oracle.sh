#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.production}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: Environment file '$ENV_FILE' not found." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

KIND="${1:-daily}"
if [[ "$KIND" != "daily" && "$KIND" != "weekly" && "$KIND" != "pre-deploy" ]]; then
  echo "ERROR: Backup kind must be 'daily', 'weekly', or 'pre-deploy'." >&2
  exit 1
fi

COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.oracle.arm64.yaml}"
COMPOSE_CMD=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

if ! "${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database pg_isready -h 127.0.0.1 -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" >/dev/null 2>&1; then
  echo "ERROR: PostgreSQL database container is not ready for backup." >&2
  exit 1
fi

BACKUP_DIR="${ROOT_DIR}/.production/backups"
mkdir -p "$BACKUP_DIR"
LOCK_FILE="${BACKUP_DIR}/backup.lock"

acquire_lock() {
  if command -v flock >/dev/null 2>&1; then
    exec 8>"$LOCK_FILE"
    if ! flock -n 8; then
      echo "ERROR: Another backup operation is currently active." >&2
      exit 1
    fi
  else
    local lock_dir="${LOCK_FILE}.dir"
    if ! mkdir "$lock_dir" 2>/dev/null; then
      echo "ERROR: Another backup operation is currently active (lockdir: $lock_dir)." >&2
      exit 1
    fi
  fi
}

release_lock() {
  if command -v flock >/dev/null 2>&1; then
    flock -u 8 2>/dev/null || true
  else
    rmdir "${LOCK_FILE}.dir" 2>/dev/null || true
  fi
}

acquire_lock

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="familyplaces-${KIND}-${STAMP}"
WORK_DIR="$(mktemp -d "${BACKUP_DIR}/.backup.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

DUMP_FILE="${WORK_DIR}/${NAME}.dump"
ENCRYPTED_FILE="${WORK_DIR}/${NAME}.dump.age"
CHECKSUM_FILE="${WORK_DIR}/${NAME}.sha256"
MANIFEST_FILE="${WORK_DIR}/${NAME}.manifest.json"

echo "==> Creating PostgreSQL database dump..."
"${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database pg_dump -Fc -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" > "$DUMP_FILE"

if command -v pg_restore >/dev/null 2>&1; then
  pg_restore --list "$DUMP_FILE" >/dev/null
fi

if [[ -n "${AGE_RECIPIENT:-}" ]] && command -v age >/dev/null 2>&1; then
  echo "==> Encrypting database dump with age..."
  age -r "$AGE_RECIPIENT" -o "$ENCRYPTED_FILE" "$DUMP_FILE"
  TARGET_FILE="$ENCRYPTED_FILE"
  S3_OBJECT_KEY="familyplaces/postgres/${KIND}/${NAME}.dump.age"
else
  echo "==> Notice: AGE_RECIPIENT or age binary not provided. Storing unencrypted dump..."
  TARGET_FILE="$DUMP_FILE"
  S3_OBJECT_KEY="familyplaces/postgres/${KIND}/${NAME}.dump"
fi

if command -v sha256sum >/dev/null 2>&1; then
  CHECKSUM=$(sha256sum "$TARGET_FILE" | awk '{print $1}')
else
  CHECKSUM=$(shasum -a 256 "$TARGET_FILE" | awk '{print $1}')
fi
echo "$CHECKSUM  $(basename "$TARGET_FILE")" > "$CHECKSUM_FILE"

pg_ver=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc 'SHOW server_version;' 2>/dev/null || echo "18")
pg_major="${pg_ver%%.*}"
postgis_ver=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc 'SELECT PostGIS_Version();' 2>/dev/null || echo "3.6")
migration=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -tAc "SELECT COALESCE(max(version), '') FROM doctrine_migration_versions;" 2>/dev/null || echo "")

if command -v jq >/dev/null 2>&1; then
  jq -n \
    --arg createdAt "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
    --arg kind "$KIND" \
    --arg sha "${RELEASE_SHA:-unknown}" \
    --arg version "${RELEASE_VERSION:-unknown}" \
    --arg major "$pg_major" \
    --arg full "$pg_ver" \
    --arg postgis "$postgis_ver" \
    --arg db "${POSTGRES_DB}" \
    --arg object "$S3_OBJECT_KEY" \
    --arg checksum "$CHECKSUM" \
    --arg migration "$migration" \
    '{
      formatVersion: 1,
      createdAt: $createdAt,
      kind: $kind,
      sourceReleaseSha: $sha,
      sourceReleaseVersion: $version,
      postgresMajor: $major,
      postgresFullVersion: $full,
      postgisVersion: $postgis,
      databaseName: $db,
      dumpFormat: "pg_dump custom",
      s3ObjectKey: $object,
      sha256Checksum: $checksum,
      applicationMigrationVersion: $migration
    }' > "$MANIFEST_FILE"
fi

PERM_BACKUP_DIR="${BACKUP_DIR}/${KIND}"
mkdir -p "$PERM_BACKUP_DIR"
cp "$TARGET_FILE" "${PERM_BACKUP_DIR}/"
cp "$CHECKSUM_FILE" "${PERM_BACKUP_DIR}/"
[[ -f "$MANIFEST_FILE" ]] && cp "$MANIFEST_FILE" "${PERM_BACKUP_DIR}/"

if [[ -n "${BACKUP_S3_BUCKET:-}" && -n "${BACKUP_S3_KEY:-}" && -n "${BACKUP_S3_SECRET:-}" ]] && command -v aws >/dev/null 2>&1; then
  echo "==> Uploading backup to S3/R2 bucket '$BACKUP_S3_BUCKET'..."
  AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY" AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET" \
    aws --endpoint-url "${BACKUP_S3_ENDPOINT:-https://r2.cloudflarestorage.com}" \
    s3 cp "$TARGET_FILE" "s3://${BACKUP_S3_BUCKET}/${S3_OBJECT_KEY}" >/dev/null

  AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY" AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET" \
    aws --endpoint-url "${BACKUP_S3_ENDPOINT:-https://r2.cloudflarestorage.com}" \
    s3 cp "$CHECKSUM_FILE" "s3://${BACKUP_S3_BUCKET}/${S3_OBJECT_KEY}.sha256" >/dev/null

  if [[ -f "$MANIFEST_FILE" ]]; then
    AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY" AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET" \
      aws --endpoint-url "${BACKUP_S3_ENDPOINT:-https://r2.cloudflarestorage.com}" \
      s3 cp "$MANIFEST_FILE" "s3://${BACKUP_S3_BUCKET}/${S3_OBJECT_KEY%.*}.manifest.json" >/dev/null
  fi
  echo "  - Uploaded to s3://${BACKUP_S3_BUCKET}/${S3_OBJECT_KEY}"
else
  echo "==> S3 backup credentials not set. Backup stored locally at ${PERM_BACKUP_DIR}."
fi

release_lock
echo "==> Backup '$NAME' completed successfully."
exit 0
