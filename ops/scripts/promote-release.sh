#!/usr/bin/env bash
set -euo pipefail

RELEASE_PATH="${1:-${VELMIX_RELEASE_PATH:-}}"
APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
CURRENT_LINK="${VELMIX_CURRENT_LINK:-$APP_ROOT/current}"
PREVIOUS_LINK="${VELMIX_PREVIOUS_LINK:-$APP_ROOT/previous}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
SYSTEMD_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
QUEUE_RESTART_SERVICE="${VELMIX_SYSTEMD_QUEUE_RESTART_SERVICE:-velmix-queue-restart.service}"
USE_SYSTEMD="${VELMIX_USE_SYSTEMD:-true}"

if [[ -z "$RELEASE_PATH" ]]; then
  echo "Usage: promote-release.sh <release-path>" >&2
  exit 1
fi

if [[ ! -d "$RELEASE_PATH" ]]; then
  echo "Release path does not exist: $RELEASE_PATH" >&2
  exit 1
fi

PREVIOUS_TARGET=""
if [[ -L "$CURRENT_LINK" ]]; then
  PREVIOUS_TARGET="$(readlink -f "$CURRENT_LINK" || true)"
fi

rollback() {
  if [[ -n "$PREVIOUS_TARGET" && -d "$PREVIOUS_TARGET" ]]; then
    ln -sfn "$PREVIOUS_TARGET" "${CURRENT_LINK}.next"
    mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"
    ln -sfn "$PREVIOUS_TARGET" "$PREVIOUS_LINK"

    if [[ "$USE_SYSTEMD" == "true" ]] && command -v systemctl >/dev/null 2>&1; then
      systemctl daemon-reload
      systemctl restart "$SYSTEMD_TARGET" || true
      systemctl start "$QUEUE_RESTART_SERVICE" || true
    fi
  fi
}

trap rollback ERR

ln -sfn "$RELEASE_PATH" "${CURRENT_LINK}.next"
mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"

if [[ -n "$PREVIOUS_TARGET" && -d "$PREVIOUS_TARGET" ]]; then
  ln -sfn "$PREVIOUS_TARGET" "$PREVIOUS_LINK"
fi

cd "$CURRENT_LINK"
"$PHP_BIN" artisan system:preflight --json --fail-on-warning

if [[ "$USE_SYSTEMD" == "true" ]] && command -v systemctl >/dev/null 2>&1; then
  systemctl daemon-reload
  systemctl restart "$SYSTEMD_TARGET"
  systemctl start "$QUEUE_RESTART_SERVICE"
  systemctl --no-pager --full status "$SYSTEMD_TARGET"
else
  "$PHP_BIN" artisan queue:restart
fi

trap - ERR

echo "Release promoted successfully: $RELEASE_PATH"
