#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

BACKUP_SOURCE="${1:-}"
TARGET_DB="${2:-}"

ENV_FILE="${ENV_FILE:-${ROOT_DIR}/.env.production}"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: Environment file '$ENV_FILE' not found." >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

if [[ -z "$BACKUP_SOURCE" ]]; then
  echo "ERROR: Backup source file or object key must be provided." >&2
  echo "Usage: $0 <backup-file-path-or-s3-key> [target-database-name]" >&2
  exit 1
fi

TARGET_DB="${TARGET_DB:-${POSTGRES_DB}_restore_$(date +%s)}"
if [[ "$TARGET_DB" == "$POSTGRES_DB" ]]; then
  echo "ERROR: Restore target database name must NOT be the active production database '$POSTGRES_DB'." >&2
  exit 1
fi

COMPOSE_FILE="${COMPOSE_FILE:-${ROOT_DIR}/compose.oracle.arm64.yaml}"
COMPOSE_CMD=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")

WORK_DIR="$(mktemp -d)"
created_db=false

cleanup() {
  local exit_code=$?
  if [[ $exit_code -ne 0 && $created_db == true ]]; then
    echo "Cleaning up created target database '$TARGET_DB'..."
    "${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database dropdb -U "${POSTGRES_USER}" --if-exists "$TARGET_DB" || true
  fi
  rm -rf "$WORK_DIR"
  exit $exit_code
}
trap cleanup EXIT

echo "==> Preparing backup file..."
if [[ -f "$BACKUP_SOURCE" ]]; then
  DUMP_FILE_LOCAL="$BACKUP_SOURCE"
elif [[ "$BACKUP_SOURCE" == "s3://"*"/"* || "$BACKUP_SOURCE" == "familyplaces/"* ]]; then
  if ! command -v aws >/dev/null 2>&1; then
    echo "ERROR: AWS CLI is required to download S3 backup." >&2
    exit 1
  fi
  s3_uri="$BACKUP_SOURCE"
  if [[ "$s3_uri" != "s3://"* ]]; then
    s3_uri="s3://${BACKUP_S3_BUCKET}/${BACKUP_SOURCE}"
  fi
  DUMP_FILE_LOCAL="${WORK_DIR}/downloaded.dump"
  echo "Downloading $s3_uri..."
  AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY" AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET" \
    aws --endpoint-url "${BACKUP_S3_ENDPOINT:-https://r2.cloudflarestorage.com}" \
    s3 cp "$s3_uri" "$DUMP_FILE_LOCAL"
else
  echo "ERROR: Backup source '$BACKUP_SOURCE' is neither a local file nor a valid S3 key." >&2
  exit 1
fi

RESTORE_DUMP="${WORK_DIR}/restore.dump"
if [[ "$DUMP_FILE_LOCAL" == *".age" ]]; then
  if [[ -z "${AGE_IDENTITY_FILE:-}" || ! -r "$AGE_IDENTITY_FILE" ]]; then
    echo "ERROR: AGE_IDENTITY_FILE must be set and readable to decrypt .age backup." >&2
    exit 1
  fi
  echo "==> Decrypting age backup file..."
  age -d -i "$AGE_IDENTITY_FILE" -o "$RESTORE_DUMP" "$DUMP_FILE_LOCAL"
else
  RESTORE_DUMP="$DUMP_FILE_LOCAL"
fi

echo "==> Setting up target database '$TARGET_DB'..."
db_exists=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$TARGET_DB';" 2>/dev/null || echo "")

if [[ "$db_exists" != "1" ]]; then
  "${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database createdb -U "${POSTGRES_USER}" "$TARGET_DB"
  created_db=true
fi

"${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "$TARGET_DB" -c "CREATE EXTENSION IF NOT EXISTS postgis;" >/dev/null

echo "==> Restoring dump into '$TARGET_DB'..."
"${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database pg_restore -U "${POSTGRES_USER}" --no-owner --role="${POSTGRES_USER}" -d "$TARGET_DB" < "$RESTORE_DUMP"

table_count=$("${COMPOSE_CMD[@]}" exec -T -e PGPASSWORD="${POSTGRES_PASSWORD}" database psql -U "${POSTGRES_USER}" -d "$TARGET_DB" -tAc "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r';" 2>/dev/null || echo "0")
echo "  - Restored database '$TARGET_DB' contains $table_count public tables."

echo "==> Database restore into '$TARGET_DB' completed successfully."
exit 0
