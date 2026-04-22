#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
ENVIRONMENT="${2:-}"
CONFIG_FILE="${3:-}"

secret_names=(
  VELMIX_SSH_HOST
  VELMIX_SSH_USER
  VELMIX_SSH_PRIVATE_KEY
  VELMIX_SSH_KNOWN_HOSTS
)

variable_names=(
  VELMIX_REMOTE_PORT
  VELMIX_REMOTE_APP_ROOT
  VELMIX_REMOTE_RELEASES_PATH
  VELMIX_REMOTE_SHARED_PATH
  VELMIX_REMOTE_ENV_FILE
  VELMIX_REMOTE_TMP_PATH
  VELMIX_REMOTE_USE_SYSTEMD
  VELMIX_REMOTE_INSTALL_UNITS
  VELMIX_REMOTE_PHP_BIN
  VELMIX_REMOTE_COMPOSER_BIN
  VELMIX_REMOTE_SYSTEMD_TARGET
  VELMIX_REMOTE_QUEUE_RESTART_SERVICE
)

if [[ -z "$REPOSITORY" || -z "$ENVIRONMENT" || -z "$CONFIG_FILE" ]]; then
  echo "Usage: sync-github-environment-config.sh <owner/repo> <environment> <config-file>" >&2
  exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required to manage GitHub environment configuration." >&2
  exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Config file not found: $CONFIG_FILE" >&2
  exit 1
fi

run_gh() {
  MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' gh "$@"
}

contains_name() {
  local needle="$1"
  shift

  for candidate in "$@"; do
    if [[ "$candidate" == "$needle" ]]; then
      return 0
    fi
  done

  return 1
}

resolve_value() {
  local raw_value="$1"

  if [[ "$raw_value" == FILE:* ]]; then
    local file_path="${raw_value#FILE:}"
    if [[ ! -f "$file_path" ]]; then
      echo "Referenced file not found: $file_path" >&2
      exit 1
    fi
    cat "$file_path"
    return 0
  fi

  printf '%s' "$raw_value"
}

while IFS= read -r line || [[ -n "$line" ]]; do
  line="${line#"${line%%[![:space:]]*}"}"
  line="${line%"${line##*[![:space:]]}"}"

  if [[ -z "$line" || "$line" == \#* ]]; then
    continue
  fi

  if [[ "$line" != *=* ]]; then
    echo "Skipping invalid config line: $line" >&2
    continue
  fi

  name="${line%%=*}"
  raw_value="${line#*=}"
  value="$(resolve_value "$raw_value")"

  if contains_name "$name" "${secret_names[@]}"; then
    printf '%s' "$value" | run_gh secret set "$name" --env "$ENVIRONMENT" -R "$REPOSITORY"
    continue
  fi

  if contains_name "$name" "${variable_names[@]}"; then
    run_gh variable set "$name" --env "$ENVIRONMENT" -R "$REPOSITORY" --body "$value"
    continue
  fi

  echo "Ignoring unsupported environment key: $name" >&2
done < "$CONFIG_FILE"
