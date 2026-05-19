#!/usr/bin/env bash

velmix_is_root() {
  [[ "${EUID:-$(id -u)}" -eq 0 ]]
}

velmix_systemctl_bin() {
  command -v systemctl
}

velmix_systemctl_requires_privilege() {
  local action="${1:-}"

  case "$action" in
    daemon-reload|restart|start|stop|reload|try-restart|reload-or-restart|reload-or-try-restart|enable|disable|mask|unmask|preset)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

velmix_can_run_privileged() {
  if [[ "$#" -eq 0 ]]; then
    return 1
  fi

  if velmix_is_root; then
    return 0
  fi

  if ! command -v sudo >/dev/null 2>&1; then
    return 1
  fi

  sudo -n -l "$@" >/dev/null 2>&1
}

velmix_run_privileged() {
  if [[ "$#" -eq 0 ]]; then
    echo "Usage: velmix_run_privileged <command> [args...]" >&2
    return 1
  fi

  if velmix_is_root; then
    "$@"
    return 0
  fi

  if ! command -v sudo >/dev/null 2>&1; then
    echo "sudo is required to execute privileged command: $*" >&2
    return 1
  fi

  if sudo -n "$@"; then
    return 0
  fi

  echo "Passwordless sudo is required for privileged command: $*" >&2
  return 1
}

velmix_run_systemctl() {
  local systemctl_bin
  systemctl_bin="$(velmix_systemctl_bin)" || {
    echo "systemctl is not available on this host." >&2
    return 1
  }

  local args=("$@")

  if velmix_systemctl_requires_privilege "${args[0]:-}"; then
    velmix_run_privileged "$systemctl_bin" "${args[@]}"
    return $?
  fi

  if "$systemctl_bin" "${args[@]}"; then
    return 0
  fi

  if velmix_can_run_privileged "$systemctl_bin" "${args[@]}"; then
    velmix_run_privileged "$systemctl_bin" "${args[@]}"
    return $?
  fi

  return 1
}
