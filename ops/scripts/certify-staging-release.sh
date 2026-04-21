#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 <release> <deploy-evidence> <rollback-evidence> [smoke-evidence] [backup-artifact] [operator]" >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-$(pwd)}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

RELEASE="$1"
DEPLOY_EVIDENCE="$2"
ROLLBACK_EVIDENCE="$3"
SMOKE_EVIDENCE="${4:-}"
BACKUP_ARTIFACT="${5:-}"
OPERATOR="${6:-}"

cd "$APP_PATH"

COMMAND=(
  "$PHP_BIN" artisan system:record-staging-certification
  "$RELEASE"
  "$DEPLOY_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  --json
)

if [[ -n "$SMOKE_EVIDENCE" ]]; then
  COMMAND+=("--smoke-evidence=$SMOKE_EVIDENCE")
fi

if [[ -n "$BACKUP_ARTIFACT" ]]; then
  COMMAND+=("--backup-artifact=$BACKUP_ARTIFACT")
fi

if [[ -n "$OPERATOR" ]]; then
  COMMAND+=("--operator=$OPERATOR")
fi

"${COMMAND[@]}"
