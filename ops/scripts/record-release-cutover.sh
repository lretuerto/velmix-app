#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 3 ]]; then
  echo "Usage: $0 <release> <cutover-evidence> <rollback-evidence> [monitoring-evidence] [operator] [notes] [--allow-warning]" >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
RELEASE="$1"
CUTOVER_EVIDENCE="$2"
ROLLBACK_EVIDENCE="$3"
MONITORING_EVIDENCE="${4:-}"
OPERATOR="${5:-}"
NOTES="${6:-}"
ALLOW_WARNING_FLAG="${7:-}"

cd "$APP_PATH"

COMMAND=(
  "$PHP_BIN" artisan system:record-release-cutover
  "$RELEASE"
  "$CUTOVER_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  --json
)

if [[ -n "$MONITORING_EVIDENCE" ]]; then
  COMMAND+=(--monitoring-evidence="$MONITORING_EVIDENCE")
fi

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
