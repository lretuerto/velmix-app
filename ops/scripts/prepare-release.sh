#!/usr/bin/env bash
set -euo pipefail

RELEASE_PATH="${1:-${VELMIX_RELEASE_PATH:-}}"
APP_ROOT="${VELMIX_APP_ROOT:-/var/www/velmix}"
SHARED_PATH="${VELMIX_SHARED_PATH:-$APP_ROOT/shared}"
ENV_FILE="${VELMIX_ENV_FILE:-$SHARED_PATH/.env}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
COMPOSER_BIN="${VELMIX_COMPOSER_BIN:-composer}"
ALLOW_WARNING="${VELMIX_ALLOW_WARNING:-${VELMIX_DEPLOY_ALLOW_WARNING:-false}}"

if [[ -z "$RELEASE_PATH" ]]; then
  echo "Usage: prepare-release.sh <release-path>" >&2
  exit 1
fi

if [[ ! -d "$RELEASE_PATH" ]]; then
  echo "Release path does not exist: $RELEASE_PATH" >&2
  exit 1
fi

if [[ ! -f "$RELEASE_PATH/artisan" || ! -f "$RELEASE_PATH/composer.json" ]]; then
  echo "Release path is not a Laravel application: $RELEASE_PATH" >&2
  exit 1
fi

"$(dirname "$0")/bootstrap-shared-path.sh"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing environment file at $ENV_FILE" >&2
  exit 1
fi

ln -sfn "$ENV_FILE" "$RELEASE_PATH/.env"

rm -rf "$RELEASE_PATH/storage"
ln -sfn "$SHARED_PATH/storage" "$RELEASE_PATH/storage"

mkdir -p "$SHARED_PATH/bootstrap/cache"
rm -rf "$RELEASE_PATH/bootstrap/cache"
ln -sfn "$SHARED_PATH/bootstrap/cache" "$RELEASE_PATH/bootstrap/cache"

cd "$RELEASE_PATH"

FAIL_OPTION="--fail-on-warning"

if [[ "$ALLOW_WARNING" == "true" ]]; then
  FAIL_OPTION="--fail-on-critical"
fi

"$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan system:preflight --json "$FAIL_OPTION"

echo "Release prepared successfully: $RELEASE_PATH"
