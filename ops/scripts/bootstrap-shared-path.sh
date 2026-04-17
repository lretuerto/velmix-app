#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
SHARED_PATH="${VELMIX_SHARED_PATH:-$APP_ROOT/shared}"
RELEASES_PATH="${VELMIX_RELEASES_PATH:-$APP_ROOT/releases}"
ENV_FILE="${VELMIX_ENV_FILE:-$SHARED_PATH/.env}"
INIT_ENV_TEMPLATE="${VELMIX_INIT_ENV_TEMPLATE:-false}"

mkdir -p "$APP_ROOT" "$SHARED_PATH" "$RELEASES_PATH"
mkdir -p "$SHARED_PATH/storage" "$SHARED_PATH/bootstrap/cache" "$SHARED_PATH/runtime"

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
