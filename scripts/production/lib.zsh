#!/usr/bin/env zsh
set -Eeuo pipefail
ROOT=${0:A:h:h:h}
ENV_FILE=${ENV_FILE:-$ROOT/.env.production}
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$ROOT/compose.yaml" -f "$ROOT/compose.prod.yaml" -f "$ROOT/compose.prod.arm64.yaml")
die() { print -u2 -- "error: $*"; exit 1; }
require_env() { [[ -f $ENV_FILE ]] || die "missing $ENV_FILE"; }
release_dir() { print -- "$ROOT/.production/releases"; }
compose() { "${COMPOSE[@]}" "$@"; }
