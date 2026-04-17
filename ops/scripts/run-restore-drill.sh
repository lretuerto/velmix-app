#!/usr/bin/env bash

set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-$(pwd)}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

cd "$APP_PATH"

"$PHP_BIN" artisan system:restore-drill --json --fail-on-warning
