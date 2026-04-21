#!/usr/bin/env bash

set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
CHECK_DATE="${1:-}"

cd "$APP_PATH"

if [[ -n "$CHECK_DATE" ]]; then
  "$PHP_BIN" artisan system:promotion-readiness --date="$CHECK_DATE" --json --fail-on-warning
else
  "$PHP_BIN" artisan system:promotion-readiness --json --fail-on-warning
fi
