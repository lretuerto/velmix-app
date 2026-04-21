#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

cd "$APP_PATH"
"$PHP_BIN" artisan system:operational-certification --json --fail-on-warning
