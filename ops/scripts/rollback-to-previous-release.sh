#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
CURRENT_LINK="${VELMIX_CURRENT_LINK:-$APP_ROOT/current}"
PREVIOUS_LINK="${VELMIX_PREVIOUS_LINK:-$APP_ROOT/previous}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
SYSTEMD_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
QUEUE_RESTART_SERVICE="${VELMIX_SYSTEMD_QUEUE_RESTART_SERVICE:-velmix-queue-restart.service}"
USE_SYSTEMD="${VELMIX_USE_SYSTEMD:-true}"

if [[ ! -L "$PREVIOUS_LINK" ]]; then
  echo "Previous release symlink not found: $PREVIOUS_LINK" >&2
  exit 1
fi

PREVIOUS_TARGET="$(readlink -f "$PREVIOUS_LINK" || true)"

if [[ -z "$PREVIOUS_TARGET" || ! -d "$PREVIOUS_TARGET" ]]; then
  echo "Previous release target is invalid: $PREVIOUS_LINK" >&2
  exit 1
fi

ln -sfn "$PREVIOUS_TARGET" "${CURRENT_LINK}.rollback"
mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"

cd "$CURRENT_LINK"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan system:preflight --json --fail-on-critical

if [[ "$USE_SYSTEMD" == "true" ]] && command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload
  systemctl restart "$SYSTEMD_TARGET"
  systemctl start "$QUEUE_RESTART_SERVICE"
  systemctl --no-pager --full status "$SYSTEMD_TARGET"
else
  "$PHP_BIN" artisan queue:restart
fi

echo "Rollback completed. Current release restored to: $PREVIOUS_TARGET"
