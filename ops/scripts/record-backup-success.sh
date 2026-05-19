#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <artifact> [checksum] [size-bytes] [driver] [generated-at]" >&2
  exit 1
fi

APP_PATH="${VELMIX_APP_PATH:-$(pwd)}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"

ARTIFACT="$1"
CHECKSUM="${2:-}"
SIZE_BYTES="${3:-}"
DRIVER="${4:-}"
GENERATED_AT="${5:-}"

cd "$APP_PATH"

COMMAND=("$PHP_BIN" artisan system:record-backup "$ARTIFACT" --json)

if [[ -n "$CHECKSUM" ]]; then
  COMMAND+=("--checksum=$CHECKSUM")
fi

if [[ -n "$SIZE_BYTES" ]]; then
  COMMAND+=("--size=$SIZE_BYTES")
fi

if [[ -n "$DRIVER" ]]; then
  COMMAND+=("--driver=$DRIVER")
fi

if [[ -n "$GENERATED_AT" ]]; then
  COMMAND+=("--generated-at=$GENERATED_AT")
fi

"${COMMAND[@]}"
