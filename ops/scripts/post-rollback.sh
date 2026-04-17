#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

cd "$APP_PATH"

"$PHP_BIN" artisan schedule:interrupt || true
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan system:preflight --json --fail-on-critical
"$PHP_BIN" artisan queue:restart
"$PHP_BIN" artisan schedule:list
