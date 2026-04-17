#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
SHARED_PATH="${VELMIX_SHARED_PATH:-$APP_ROOT/shared}"
RELEASES_PATH="${VELMIX_RELEASES_PATH:-$APP_ROOT/releases}"
ENV_FILE="${VELMIX_ENV_FILE:-$SHARED_PATH/.env}"
INIT_ENV_TEMPLATE="${VELMIX_INIT_ENV_TEMPLATE:-false}"
BACKUP_STORAGE_PATH="${VELMIX_BACKUP_STORAGE_PATH:-$SHARED_PATH/backups}"
BACKUP_HISTORY_PATH="${VELMIX_BACKUP_HISTORY_PATH:-$BACKUP_STORAGE_PATH/history}"
RESTORE_DRILL_PATH="${VELMIX_RESTORE_DRILL_PATH:-$SHARED_PATH/restore-drills}"

mkdir -p "$APP_ROOT" "$SHARED_PATH" "$RELEASES_PATH"
mkdir -p "$SHARED_PATH/storage" "$SHARED_PATH/bootstrap/cache" "$SHARED_PATH/runtime"
mkdir -p "$BACKUP_STORAGE_PATH" "$BACKUP_HISTORY_PATH" "$RESTORE_DRILL_PATH"

if [[ ! -f "$ENV_FILE" ]]; then
  if [[ "$INIT_ENV_TEMPLATE" == "true" ]]; then
    cp .env.example "$ENV_FILE"
    echo "Created environment file template at $ENV_FILE"
  else
    echo "Missing environment file at $ENV_FILE" >&2
    echo "Create it from ops/systemd/velmix-app.env.example before promoting traffic." >&2
    exit 1
  fi
fi

echo "Shared release layout is ready:"
echo "  APP_ROOT=$APP_ROOT"
echo "  SHARED_PATH=$SHARED_PATH"
echo "  RELEASES_PATH=$RELEASES_PATH"
echo "  ENV_FILE=$ENV_FILE"
echo "  BACKUP_STORAGE_PATH=$BACKUP_STORAGE_PATH"
echo "  RESTORE_DRILL_PATH=$RESTORE_DRILL_PATH"
