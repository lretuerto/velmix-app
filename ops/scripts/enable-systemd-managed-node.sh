#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
SHARED_PATH="${VELMIX_SHARED_PATH:-/var/www/velmix/shared}"
SYSTEMD_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
SYSTEMD_ENV_DIR="${VELMIX_SYSTEMD_ENV_DIR:-/etc/velmix}"
SYSTEMD_ENV_FILE="${VELMIX_SYSTEMD_ENV_FILE:-$SYSTEMD_ENV_DIR/velmix.env}"
SYSTEMD_ENV_GROUP="${VELMIX_SYSTEMD_ENV_GROUP:-www-data}"
SOURCE_ENV_FILE="${VELMIX_SYSTEMD_SOURCE_ENV_FILE:-${VELMIX_ENV_FILE:-$SHARED_PATH/.env}}"
INSTALL_SCRIPT="$APP_PATH/ops/scripts/install-systemd-units.sh"
HEALTH_SCRIPT="$APP_PATH/ops/scripts/check-backend-health.sh"
SCHEDULER_SERVICE="${VELMIX_SCHEDULER_SERVICE:-velmix-scheduler.service}"
QUEUE_WORKER_SERVICE="${VELMIX_QUEUE_WORKER_SERVICE:-velmix-queue-worker.service}"

if [[ ! -d "$APP_PATH" ]]; then
  echo "Missing application path at $APP_PATH" >&2
  exit 1
fi

if [[ ! -f "$INSTALL_SCRIPT" ]]; then
  echo "Missing install-systemd-units.sh at $INSTALL_SCRIPT" >&2
  exit 1
fi

if [[ ! -f "$HEALTH_SCRIPT" ]]; then
  echo "Missing check-backend-health.sh at $HEALTH_SCRIPT" >&2
  exit 1
fi

if [[ ! -f "$SOURCE_ENV_FILE" ]]; then
  echo "Missing source environment file at $SOURCE_ENV_FILE" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemctl is required to enable the managed backend target." >&2
  exit 1
fi

if ! getent group "$SYSTEMD_ENV_GROUP" >/dev/null 2>&1; then
  echo "Missing group $SYSTEMD_ENV_GROUP required for $SYSTEMD_ENV_FILE" >&2
  exit 1
fi

install -d -m 0755 "$SYSTEMD_ENV_DIR"

VELMIX_SYNC_SYSTEMD_ENV=true \
VELMIX_SYSTEMD_SOURCE_ENV_FILE="$SOURCE_ENV_FILE" \
VELMIX_APP_PATH="$APP_PATH" \
bash "$INSTALL_SCRIPT"

chown root:"$SYSTEMD_ENV_GROUP" "$SYSTEMD_ENV_FILE"
chmod 0640 "$SYSTEMD_ENV_FILE"

systemctl enable --now "$SYSTEMD_TARGET"

systemctl --no-pager --full status "$SYSTEMD_TARGET"
systemctl --no-pager --full status "$SCHEDULER_SERVICE"
systemctl --no-pager --full status "$QUEUE_WORKER_SERVICE"

VELMIX_APP_PATH="$APP_PATH" \
bash "$HEALTH_SCRIPT"

echo "Systemd-managed backend enabled successfully for $APP_PATH."
