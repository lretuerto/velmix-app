#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/systemctl-helpers.sh"

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
COMPOSER_BIN="${VELMIX_COMPOSER_BIN:-composer}"
BACKEND_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
QUEUE_RESTART_SERVICE="${VELMIX_SYSTEMD_QUEUE_RESTART_SERVICE:-velmix-queue-restart.service}"
USE_SYSTEMD="${VELMIX_USE_SYSTEMD:-true}"

cd "$APP_PATH"

"$PHP_BIN" artisan schedule:interrupt || true
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan system:preflight --json --fail-on-warning
if [[ "$USE_SYSTEMD" == "true" ]] && velmix_systemctl_bin >/dev/null 2>&1; then
  velmix_run_systemctl daemon-reload
  velmix_run_systemctl restart "$BACKEND_TARGET"
  velmix_run_systemctl start "$QUEUE_RESTART_SERVICE"
else
  "$PHP_BIN" artisan queue:restart
fi
"$PHP_BIN" artisan schedule:list
