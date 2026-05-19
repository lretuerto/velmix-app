#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
  echo "This script currently supports Debian/Ubuntu hosts with apt-get." >&2
  exit 1
fi

APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
SHARED_PATH="${VELMIX_SHARED_PATH:-$APP_ROOT/shared}"
RELEASES_PATH="${VELMIX_RELEASES_PATH:-$APP_ROOT/releases}"
ENV_FILE="${VELMIX_ENV_FILE:-$SHARED_PATH/.env}"
REMOTE_TMP_PATH="${VELMIX_REMOTE_TMP_PATH:-/tmp/velmix-deploy}"
DEPLOY_USER="${VELMIX_DEPLOY_USER:-deploy}"
DEPLOY_GROUP="${VELMIX_DEPLOY_GROUP:-www-data}"
SYSTEMD_ENV_GROUP="${VELMIX_SYSTEMD_ENV_GROUP:-www-data}"
SSH_PORT="${VELMIX_SSH_PORT:-22}"
PHP_VERSION="${VELMIX_PHP_VERSION:-8.3}"
ENABLE_UFW="${VELMIX_ENABLE_UFW:-true}"
INSTALL_CERTBOT="${VELMIX_INSTALL_CERTBOT:-true}"
INSTALL_FAIL2BAN="${VELMIX_INSTALL_FAIL2BAN:-true}"
INIT_ENV_TEMPLATE="${VELMIX_INIT_ENV_TEMPLATE:-false}"

php_packages=(
  "php${PHP_VERSION}-fpm"
  "php${PHP_VERSION}-cli"
  "php${PHP_VERSION}-common"
  "php${PHP_VERSION}-mysql"
  "php${PHP_VERSION}-mbstring"
  "php${PHP_VERSION}-xml"
  "php${PHP_VERSION}-curl"
  "php${PHP_VERSION}-zip"
  "php${PHP_VERSION}-bcmath"
  "php${PHP_VERSION}-intl"
  "php${PHP_VERSION}-gd"
  "php${PHP_VERSION}-sqlite3"
  "php${PHP_VERSION}-redis"
)

base_packages=(
  acl
  ca-certificates
  composer
  curl
  git
  lsb-release
  mariadb-server
  nginx
  redis-server
  software-properties-common
  supervisor
  unzip
)

if [[ "$INSTALL_CERTBOT" == "true" ]]; then
  base_packages+=(certbot python3-certbot-nginx)
fi

if [[ "$INSTALL_FAIL2BAN" == "true" ]]; then
  base_packages+=(fail2ban)
fi

if [[ "$ENABLE_UFW" == "true" ]]; then
  base_packages+=(ufw)
fi

echo "Updating apt metadata..."
apt-get update

echo "Installing Ubuntu packages for a VELMiX node..."
apt-get install -y "${base_packages[@]}" "${php_packages[@]}"

services_to_enable=(
  "php${PHP_VERSION}-fpm"
  "nginx"
  "mariadb"
  "redis-server"
  "supervisor"
)

if [[ "$INSTALL_FAIL2BAN" == "true" ]]; then
  services_to_enable+=(fail2ban)
fi

for service in "${services_to_enable[@]}"; do
  systemctl enable --now "$service"
done

if [[ "$ENABLE_UFW" == "true" ]]; then
  ufw allow "${SSH_PORT}/tcp"
  ufw allow 80/tcp
  ufw allow 443/tcp
  ufw --force enable
fi

if ! getent group "$DEPLOY_GROUP" >/dev/null 2>&1; then
  echo "Missing deploy group $DEPLOY_GROUP" >&2
  exit 1
fi

if ! getent group "$SYSTEMD_ENV_GROUP" >/dev/null 2>&1; then
  echo "Missing systemd environment group $SYSTEMD_ENV_GROUP" >&2
  exit 1
fi

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "$DEPLOY_USER"
fi

usermod -aG "$DEPLOY_GROUP" "$DEPLOY_USER"

install -d -m 0755 /etc/velmix
install -d -m 0775 -o "$DEPLOY_USER" -g "$DEPLOY_GROUP" "$APP_ROOT" "$SHARED_PATH" "$RELEASES_PATH" "$REMOTE_TMP_PATH"

VELMIX_APP_ROOT="$APP_ROOT" \
VELMIX_SHARED_PATH="$SHARED_PATH" \
VELMIX_RELEASES_PATH="$RELEASES_PATH" \
VELMIX_ENV_FILE="$ENV_FILE" \
VELMIX_INIT_ENV_TEMPLATE="$INIT_ENV_TEMPLATE" \
bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/bootstrap-shared-path.sh"

chown -R "$DEPLOY_USER":"$DEPLOY_GROUP" "$APP_ROOT" "$REMOTE_TMP_PATH"
chmod 0775 "$REMOTE_TMP_PATH"

echo "VELMiX Ubuntu node provisioned successfully."
echo "  APP_ROOT=$APP_ROOT"
echo "  SHARED_PATH=$SHARED_PATH"
echo "  RELEASES_PATH=$RELEASES_PATH"
echo "  ENV_FILE=$ENV_FILE"
echo "  DEPLOY_USER=$DEPLOY_USER"
echo "  DEPLOY_GROUP=$DEPLOY_GROUP"
echo "  SSH_PORT=$SSH_PORT"
echo "Next steps:"
echo "  1. Populate $ENV_FILE with the target environment values."
echo "  2. Configure the vhost and TLS for the production hostname."
echo "  3. Run ops/scripts/install-deploy-systemd-sudoers.sh as root."
echo "  4. Install and validate the systemd units with ops/scripts/enable-systemd-managed-node.sh."
echo "  5. Configure the GitHub production environment secrets and variables."
