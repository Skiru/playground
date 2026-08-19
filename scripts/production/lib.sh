#!/bin/sh
set -eu

# Resolve directory portably
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
ENV_FILE=${ENV_FILE:-$ROOT/.env.production}

die() {
  printf 'error: %s\n' "$*" >&2
  exit 1
}

# Function to load env file respecting process environment precedence:
# process environment > env file > defaults
load_env() {
  env_path=${1:-$ENV_FILE}
  [ -f "$env_path" ] || die "missing environment file: $env_path"

  while IFS= read -r line || [ -n "$line" ]; do
    # Strip leading and trailing whitespace
    line=$(printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
    case "$line" in
      ''|'#'*) continue ;;
    esac
    case "$line" in
      *=*)
        var_name=${line%%=*}
        var_val=${line#*=}
        # Validate identifier
        case "$var_name" in
          [a-zA-Z_][a-zA-Z0-9_]*)
            eval "already_set=\${${var_name}+true}"
            if [ "${already_set:-false}" != "true" ]; then
              # Strip quotes if present
              case "$var_val" in
                '"'*'"') var_val=${var_val#\"}; var_val=${var_val%\"} ;;
                "'"*"'") var_val=${var_val#\'}; var_val=${var_val%\'} ;;
              esac
              export "$var_name=$var_val"
            fi
            ;;
        esac
        ;;
    esac
  done < "$env_path"
}

require_env() {
  [ -f "$ENV_FILE" ] || die "missing $ENV_FILE"
  load_env "$ENV_FILE"
}

release_dir() {
  printf '%s/.production/releases' "$ROOT"
}

release_descriptor() {
  printf '%s/current.json' "$(release_dir)"
}

compose() {
  if [ "${CERTIFICATION_DIRECT_DATABASE:-false}" = "true" ] && [ "${1:-}" = "exec" ] && [ "${2:-}" = "-T" ] && [ "${3:-}" = "database" ]; then
    shift 3
    PGHOST=${PGHOST:-database} PGPORT=${PGPORT:-5432} PGPASSWORD=${PGPASSWORD:-${POSTGRES_PASSWORD:-}} command "$@"
    return
  fi
  ENV_FILE="$ENV_FILE" docker compose --env-file "$ENV_FILE" -f "$ROOT/compose.yaml" -f "$ROOT/compose.prod.yaml" "$@"
}

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  else
    die "neither sha256sum nor shasum is available"
  fi
}

backup_aws() {
  AWS_ACCESS_KEY_ID="${BACKUP_S3_KEY:-}" AWS_SECRET_ACCESS_KEY="${BACKUP_S3_SECRET:-}" aws --endpoint-url "${BACKUP_S3_ENDPOINT:-}" "$@"
}

backup_prefix() {
  printf 'familyplaces/postgres'
}
