#!/usr/bin/env zsh
set -Eeuo pipefail
ROOT=${0:A:h:h:h}
ENV_FILE=${ENV_FILE:-$ROOT/.env.production}
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$ROOT/compose.yaml" -f "$ROOT/compose.prod.yaml" -f "$ROOT/compose.prod.arm64.yaml")
die() { print -u2 -- "error: $*"; exit 1; }
require_env() { [[ -f $ENV_FILE ]] || die "missing $ENV_FILE"; }
release_dir() { print -- "$ROOT/.production/releases"; }
compose() { "${COMPOSE[@]}" "$@"; }
sha256() { shasum -a 256 "$1" | awk '{print $1}'; }
backup_aws() { AWS_ACCESS_KEY_ID="$BACKUP_S3_KEY" AWS_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET" aws --endpoint-url "$BACKUP_S3_ENDPOINT" "$@"; }
backup_prefix() { print -- "familyplaces/postgres"; }
release_descriptor() { print -- "$(release_dir)/current.json"; }
