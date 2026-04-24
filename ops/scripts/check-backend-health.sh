#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/systemctl-helpers.sh"

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
BACKEND_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"

cd "$APP_PATH"

"$PHP_BIN" artisan system:readiness --json
"$PHP_BIN" artisan system:preflight --json
"$PHP_BIN" artisan system:alerts --json
"$PHP_BIN" artisan system:observability-report --json
"$PHP_BIN" artisan system:backup-readiness --json
"$PHP_BIN" artisan system:staging-certification --json
"$PHP_BIN" artisan system:promotion-readiness --json
"$PHP_BIN" artisan system:cutover-readiness --json
"$PHP_BIN" artisan system:operational-certification --json
"$PHP_BIN" artisan billing:dispatch-outbox --limit=20 --graceful-if-unmigrated
"$PHP_BIN" artisan billing:reconcile-pending --limit=20 --graceful-if-unmigrated
"$PHP_BIN" artisan schedule:list

if velmix_systemctl_bin >/dev/null 2>&1; then
  velmix_run_systemctl --no-pager --full status "$BACKEND_TARGET" || true
fi
