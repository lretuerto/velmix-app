#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="${VELMIX_REMOTE_HOST:-}"
REMOTE_USER="${VELMIX_REMOTE_USER:-}"
REMOTE_PORT="${VELMIX_REMOTE_PORT:-22}"
REMOTE_APP_ROOT="${VELMIX_REMOTE_APP_ROOT:-/var/www/velmix}"
REMOTE_RELEASES_PATH="${VELMIX_REMOTE_RELEASES_PATH:-$REMOTE_APP_ROOT/releases}"
REMOTE_SHARED_PATH="${VELMIX_REMOTE_SHARED_PATH:-$REMOTE_APP_ROOT/shared}"
REMOTE_ENV_FILE="${VELMIX_REMOTE_ENV_FILE:-$REMOTE_SHARED_PATH/.env}"
REMOTE_TMP_PATH="${VELMIX_REMOTE_TMP_PATH:-/tmp/velmix-deploy}"
REMOTE_USE_SYSTEMD="${VELMIX_REMOTE_USE_SYSTEMD:-true}"
REMOTE_INSTALL_UNITS="${VELMIX_REMOTE_INSTALL_UNITS:-false}"
REMOTE_PHP_BIN="${VELMIX_REMOTE_PHP_BIN:-php}"
REMOTE_COMPOSER_BIN="${VELMIX_REMOTE_COMPOSER_BIN:-composer}"
REMOTE_SYSTEMD_TARGET="${VELMIX_REMOTE_SYSTEMD_TARGET:-velmix-backend.target}"
REMOTE_QUEUE_RESTART_SERVICE="${VELMIX_REMOTE_QUEUE_RESTART_SERVICE:-velmix-queue-restart.service}"

required_vars=(
  REMOTE_HOST
  REMOTE_USER
  REMOTE_PORT
  REMOTE_APP_ROOT
  REMOTE_RELEASES_PATH
  REMOTE_SHARED_PATH
  REMOTE_ENV_FILE
  REMOTE_TMP_PATH
  REMOTE_PHP_BIN
  REMOTE_COMPOSER_BIN
  VELMIX_SSH_KNOWN_HOSTS
)

for name in "${required_vars[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required variable: $name" >&2
    exit 1
  fi
done

if ! command -v ssh >/dev/null 2>&1; then
  echo "This script requires ssh on the runner." >&2
  exit 1
fi

quote() {
  printf '%q' "$1"
}

REMOTE_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
SSH_OPTS=(-p "$REMOTE_PORT" -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")

remote_env=(
  "REMOTE_HOST=$(quote "$REMOTE_HOST")"
  "REMOTE_APP_ROOT=$(quote "$REMOTE_APP_ROOT")"
  "REMOTE_RELEASES_PATH=$(quote "$REMOTE_RELEASES_PATH")"
  "REMOTE_SHARED_PATH=$(quote "$REMOTE_SHARED_PATH")"
  "REMOTE_ENV_FILE=$(quote "$REMOTE_ENV_FILE")"
  "REMOTE_TMP_PATH=$(quote "$REMOTE_TMP_PATH")"
  "REMOTE_USE_SYSTEMD=$(quote "$REMOTE_USE_SYSTEMD")"
  "REMOTE_INSTALL_UNITS=$(quote "$REMOTE_INSTALL_UNITS")"
  "REMOTE_PHP_BIN=$(quote "$REMOTE_PHP_BIN")"
  "REMOTE_COMPOSER_BIN=$(quote "$REMOTE_COMPOSER_BIN")"
  "REMOTE_SYSTEMD_TARGET=$(quote "$REMOTE_SYSTEMD_TARGET")"
  "REMOTE_QUEUE_RESTART_SERVICE=$(quote "$REMOTE_QUEUE_RESTART_SERVICE")"
)

ssh "${SSH_OPTS[@]}" "$REMOTE_TARGET" "${remote_env[*]} bash -s" <<'EOF'
set -euo pipefail

issues=()
status="ok"

json_array() {
  local first=true
  printf '['
  for value in "$@"; do
    if [[ "$first" == true ]]; then
      first=false
    else
      printf ','
    fi
    printf '"%s"' "$value"
  done
  printf ']'
}

add_issue() {
  issues+=("$1")
  status="blocked"
}

mkdir -p "$REMOTE_APP_ROOT" "$REMOTE_RELEASES_PATH" "$REMOTE_SHARED_PATH" "$(dirname "$REMOTE_ENV_FILE")" "$REMOTE_TMP_PATH"

tmp_path_ready=true
release_path_ready=true
shared_path_ready=true
env_file_present=false
tar_available=false
php_available=false
composer_available=false
systemctl_available=false
systemd_target_present=false
queue_restart_service_present=false
systemd_install_path_writable=false

if [[ -f "$REMOTE_ENV_FILE" ]]; then
  env_file_present=true
else
  add_issue "remote_env_file_missing"
fi

if command -v tar >/dev/null 2>&1; then
  tar_available=true
else
  add_issue "remote_tar_missing"
fi

if command -v "$REMOTE_PHP_BIN" >/dev/null 2>&1; then
  php_available=true
else
  add_issue "remote_php_missing"
fi

if command -v "$REMOTE_COMPOSER_BIN" >/dev/null 2>&1; then
  composer_available=true
else
  add_issue "remote_composer_missing"
fi

if [[ "$REMOTE_USE_SYSTEMD" == "true" ]]; then
  if command -v systemctl >/dev/null 2>&1; then
    systemctl_available=true
  else
    add_issue "remote_systemctl_missing"
  fi

  if [[ "$REMOTE_INSTALL_UNITS" == "true" ]]; then
    if [[ -w /etc/systemd/system ]]; then
      systemd_install_path_writable=true
    else
      add_issue "remote_systemd_dir_not_writable"
    fi
  elif [[ "$systemctl_available" == "true" ]]; then
    if systemctl cat "$REMOTE_SYSTEMD_TARGET" >/dev/null 2>&1; then
      systemd_target_present=true
    else
      add_issue "remote_systemd_target_missing"
    fi

    if systemctl cat "$REMOTE_QUEUE_RESTART_SERVICE" >/dev/null 2>&1; then
      queue_restart_service_present=true
    else
      add_issue "remote_queue_restart_service_missing"
    fi
  fi
fi

printf '{\n'
printf '  "status": "%s",\n' "$status"
printf '  "host": "%s",\n' "$REMOTE_HOST"
printf '  "paths": {\n'
printf '    "app_root": "%s",\n' "$REMOTE_APP_ROOT"
printf '    "releases_path": "%s",\n' "$REMOTE_RELEASES_PATH"
printf '    "shared_path": "%s",\n' "$REMOTE_SHARED_PATH"
printf '    "env_file": "%s",\n' "$REMOTE_ENV_FILE"
printf '    "tmp_path": "%s"\n' "$REMOTE_TMP_PATH"
printf '  },\n'
printf '  "checks": {\n'
printf '    "tmp_path_ready": %s,\n' "$tmp_path_ready"
printf '    "release_path_ready": %s,\n' "$release_path_ready"
printf '    "shared_path_ready": %s,\n' "$shared_path_ready"
printf '    "env_file_present": %s,\n' "$env_file_present"
printf '    "tar_available": %s,\n' "$tar_available"
printf '    "php_available": %s,\n' "$php_available"
printf '    "composer_available": %s,\n' "$composer_available"
printf '    "systemctl_available": %s,\n' "$systemctl_available"
printf '    "systemd_target_present": %s,\n' "$systemd_target_present"
printf '    "queue_restart_service_present": %s,\n' "$queue_restart_service_present"
printf '    "systemd_install_path_writable": %s\n' "$systemd_install_path_writable"
printf '  },\n'
printf '  "issues": %s\n' "$(json_array "${issues[@]}")"
printf '}\n'

if [[ "$status" != "ok" ]]; then
  exit 1
fi
EOF
