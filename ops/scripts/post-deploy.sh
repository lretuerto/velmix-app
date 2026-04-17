#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
COMPOSER_BIN="${VELMIX_COMPOSER_BIN:-composer}"

cd "$APP_PATH"

"$PHP_BIN" artisan schedule:interrupt || true
"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan system:preflight --json --fail-on-warning
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan schedule:list
