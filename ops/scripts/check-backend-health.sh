#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
BACKEND_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"

cd "$APP_PATH"

"$PHP_BIN" artisan system:readiness --json
"$PHP_BIN" artisan system:preflight --json
"$PHP_BIN" artisan system:alerts --json
"$PHP_BIN" artisan billing:dispatch-outbox --limit=20 --graceful-if-unmigrated
"$PHP_BIN" artisan billing:reconcile-pending --limit=20 --graceful-if-unmigrated
"$PHP_BIN" artisan schedule:list

if command -v systemctl >/dev/null 2>&1; then
  systemctl --no-pager --full status "$BACKEND_TARGET" || true
fi
