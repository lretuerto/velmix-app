#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 5 ]]; then
  echo "Usage: $0 <release> <deploy-evidence> <rollback-evidence> <backup-artifact> <restore-evidence> [monitoring-evidence] [operator] [notes] [--allow-warning]" >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

RELEASE="$1"
DEPLOY_EVIDENCE="$2"
ROLLBACK_EVIDENCE="$3"
BACKUP_ARTIFACT="$4"
RESTORE_EVIDENCE="$5"
MONITORING_EVIDENCE="${6:-}"
OPERATOR="${7:-}"
NOTES="${8:-}"
ALLOW_WARNING_FLAG="${9:-}"

cd "$APP_PATH"

ARGS=(
  artisan
  system:record-operational-certification
  "$RELEASE"
  "$DEPLOY_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  "$BACKUP_ARTIFACT"
  "$RESTORE_EVIDENCE"
  --json
)

if [[ -n "$MONITORING_EVIDENCE" ]]; then
  ARGS+=("--monitoring-evidence=$MONITORING_EVIDENCE")
fi

if [[ -n "$OPERATOR" ]]; then
  ARGS+=("--operator=$OPERATOR")
fi

if [[ -n "$NOTES" ]]; then
  ARGS+=("--notes=$NOTES")
fi

if [[ "$ALLOW_WARNING_FLAG" == "--allow-warning" || "${VELMIX_ALLOW_WARNING:-false}" == "true" ]]; then
  ARGS+=("--allow-warning")
fi

"$PHP_BIN" "${ARGS[@]}"
