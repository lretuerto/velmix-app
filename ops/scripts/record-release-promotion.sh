#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 <release> <approval-evidence> <rollback-evidence> [operator] [notes] [--allow-warning]" >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
RELEASE="$1"
APPROVAL_EVIDENCE="$2"
ROLLBACK_EVIDENCE="$3"
OPERATOR="${4:-}"
NOTES="${5:-}"
ALLOW_WARNING_FLAG="${6:-}"

cd "$APP_PATH"

COMMAND=(
  "$PHP_BIN" artisan system:record-release-promotion
  "$RELEASE"
  "$APPROVAL_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  --json
)

if [[ -n "$OPERATOR" ]]; then
  COMMAND+=(--operator="$OPERATOR")
fi

if [[ -n "$NOTES" ]]; then
  COMMAND+=(--notes="$NOTES")
fi

if [[ "$ALLOW_WARNING_FLAG" == "--allow-warning" || "${VELMIX_ALLOW_WARNING:-false}" == "true" ]]; then
  COMMAND+=(--allow-warning)
fi

"${COMMAND[@]}"
