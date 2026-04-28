#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-/var/www/velmix/current}"
SHARED_PATH="${VELMIX_SHARED_PATH:-/var/www/velmix/shared}"
SHARED_ENV_FILE="${VELMIX_ENV_FILE:-$SHARED_PATH/.env}"
SYSTEMD_ENV_DIR="${VELMIX_SYSTEMD_ENV_DIR:-/etc/velmix}"
SYSTEMD_ENV_FILE="${VELMIX_SYSTEMD_ENV_FILE:-$SYSTEMD_ENV_DIR/velmix.env}"
SYSTEMD_ENV_GROUP="${VELMIX_SYSTEMD_ENV_GROUP:-www-data}"
SYSTEMD_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
HEALTH_SCRIPT="$APP_PATH/ops/scripts/check-backend-health.sh"
TARGET_APP_URL="${VELMIX_TARGET_APP_URL:-https://velmix.gacicorporacion.com}"
BACKUP_SUFFIX="${VELMIX_ENV_BACKUP_SUFFIX:-pre-production-$(date -u +%Y%m%d-%H%M%S)}"
SHARED_ENV_BACKUP="${SHARED_ENV_FILE}.${BACKUP_SUFFIX}.bak"
SYSTEMD_ENV_BACKUP="${SYSTEMD_ENV_FILE}.${BACKUP_SUFFIX}.bak"

if [[ ! -d "$APP_PATH" ]]; then
  echo "Missing application path at $APP_PATH" >&2
  exit 1
fi

if [[ ! -f "$SHARED_ENV_FILE" ]]; then
  echo "Missing shared environment file at $SHARED_ENV_FILE" >&2
  exit 1
fi

if [[ ! -f "$HEALTH_SCRIPT" ]]; then
  echo "Missing health check script at $HEALTH_SCRIPT" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "systemctl is required to perform the production cutover." >&2
  exit 1
fi

if ! getent group "$SYSTEMD_ENV_GROUP" >/dev/null 2>&1; then
  echo "Missing group $SYSTEMD_ENV_GROUP required for $SYSTEMD_ENV_FILE" >&2
  exit 1
fi

install -d -m 0755 "$SYSTEMD_ENV_DIR"
cp "$SHARED_ENV_FILE" "$SHARED_ENV_BACKUP"

if [[ -f "$SYSTEMD_ENV_FILE" ]]; then
  cp "$SYSTEMD_ENV_FILE" "$SYSTEMD_ENV_BACKUP"
fi

upsert_env() {
  local key="$1"
  local value="$2"

  if grep -q "^${key}=" "$SHARED_ENV_FILE"; then
    sed -i "s#^${key}=.*#${key}=${value}#" "$SHARED_ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$SHARED_ENV_FILE"
  fi
}

upsert_env "APP_ENV" "production"
upsert_env "APP_URL" "$TARGET_APP_URL"
upsert_env "VELMIX_STAGING_CERTIFICATION_ENV" "staging"
upsert_env "VELMIX_STAGING_CERTIFICATION_REQUIRED_ENVS" "staging,production"
upsert_env "VELMIX_RELEASE_PROMOTION_ENV" "staging"
upsert_env "VELMIX_RELEASE_PROMOTION_REQUIRED_ENVS" "staging"
upsert_env "VELMIX_RELEASE_CUTOVER_ENV" "production"
upsert_env "VELMIX_RELEASE_CUTOVER_REQUIRED_ENVS" "production"
upsert_env "VELMIX_OPERATIONAL_CERTIFICATION_ENV" "production"
upsert_env "VELMIX_OPERATIONAL_CERTIFICATION_REQUIRED_ENVS" "production"

install -m 0640 "$SHARED_ENV_FILE" "$SYSTEMD_ENV_FILE"
chown root:"$SYSTEMD_ENV_GROUP" "$SYSTEMD_ENV_FILE"

systemctl daemon-reload
systemctl restart "$SYSTEMD_TARGET"
systemctl --no-pager --full status "$SYSTEMD_TARGET"

VELMIX_APP_PATH="$APP_PATH" \
VELMIX_PHP_BIN="${VELMIX_PHP_BIN:-php}" \
bash "$HEALTH_SCRIPT"

echo "Single-host production cutover completed successfully."
echo "Shared env backup: $SHARED_ENV_BACKUP"
if [[ -f "$SYSTEMD_ENV_BACKUP" ]]; then
  echo "Systemd env backup: $SYSTEMD_ENV_BACKUP"
fi
