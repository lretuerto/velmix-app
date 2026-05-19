#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
SYSTEMD_DIR="${VELMIX_SYSTEMD_DIR:-/etc/systemd/system}"
SYSTEMD_ENV_DIR="${VELMIX_SYSTEMD_ENV_DIR:-/etc/velmix}"
SYSTEMD_ENV_FILE="${VELMIX_SYSTEMD_ENV_FILE:-$SYSTEMD_ENV_DIR/velmix.env}"
SOURCE_ENV_FILE="${VELMIX_SYSTEMD_SOURCE_ENV_FILE:-${VELMIX_ENV_FILE:-${VELMIX_SHARED_PATH:-/var/www/velmix/shared}/.env}}"
SYNC_ENV_FILE="${VELMIX_SYNC_SYSTEMD_ENV:-false}"

if [[ ! -d "$APP_PATH/ops/systemd" ]]; then
  echo "Missing ops/systemd assets under $APP_PATH" >&2
  exit 1
fi

install -d "$SYSTEMD_DIR" "$SYSTEMD_ENV_DIR"

for unit in velmix-backend.target velmix-scheduler.service velmix-queue-worker.service velmix-queue-restart.service; do
  install -m 0644 "$APP_PATH/ops/systemd/$unit" "$SYSTEMD_DIR/$unit"
done

if [[ "$SYNC_ENV_FILE" == "true" ]]; then
  if [[ ! -f "$SOURCE_ENV_FILE" ]]; then
    echo "Missing source environment file at $SOURCE_ENV_FILE" >&2
    exit 1
  fi

  install -m 0640 "$SOURCE_ENV_FILE" "$SYSTEMD_ENV_FILE"
  echo "Synchronized environment file from $SOURCE_ENV_FILE to $SYSTEMD_ENV_FILE"
elif [[ ! -f "$SYSTEMD_ENV_FILE" ]]; then
  install -m 0640 "$APP_PATH/ops/systemd/velmix-app.env.example" "$SYSTEMD_ENV_FILE"
  echo "Created environment file template at $SYSTEMD_ENV_FILE"
fi

systemctl daemon-reload
systemctl enable velmix-backend.target
systemctl enable velmix-queue-restart.service

echo "Systemd assets installed successfully."
echo "Review $SYSTEMD_ENV_FILE before starting services in production."
