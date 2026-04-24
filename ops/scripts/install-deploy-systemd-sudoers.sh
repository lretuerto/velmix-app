#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "This script must run as root." >&2
  exit 1
fi

DEPLOY_USER="${VELMIX_DEPLOY_USER:-deploy}"
SYSTEMCTL_BIN="${VELMIX_SYSTEMCTL_BIN:-/usr/bin/systemctl}"
SYSTEMD_TARGET="${VELMIX_SYSTEMD_TARGET:-velmix-backend.target}"
QUEUE_RESTART_SERVICE="${VELMIX_QUEUE_RESTART_SERVICE:-velmix-queue-restart.service}"
SUDOERS_PATH="${VELMIX_SYSTEMD_SUDOERS_PATH:-/etc/sudoers.d/velmix-deploy-systemd}"
VISUDO_BIN="${VELMIX_VISUDO_BIN:-}"

if [[ -z "$VISUDO_BIN" ]]; then
  VISUDO_BIN="$(command -v visudo || true)"
fi

if [[ -z "$VISUDO_BIN" ]]; then
  echo "visudo is required to validate the generated sudoers file." >&2
  exit 1
fi

if [[ ! -x "$SYSTEMCTL_BIN" ]]; then
  echo "Missing systemctl binary at $SYSTEMCTL_BIN" >&2
  exit 1
fi

if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  echo "Missing deploy user $DEPLOY_USER" >&2
  exit 1
fi

sudoers_dir="$(dirname "$SUDOERS_PATH")"
install -d -m 0755 "$sudoers_dir"

tmp_file="$(mktemp "$sudoers_dir/velmix-deploy-systemd.XXXXXX")"

cleanup() {
  rm -f "$tmp_file"
}

trap cleanup EXIT

cat >"$tmp_file" <<EOF
$DEPLOY_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN daemon-reload
$DEPLOY_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN restart $SYSTEMD_TARGET
$DEPLOY_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN start $QUEUE_RESTART_SERVICE
$DEPLOY_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN status $SYSTEMD_TARGET
EOF

chmod 0440 "$tmp_file"
"$VISUDO_BIN" -cf "$tmp_file"
mv -f "$tmp_file" "$SUDOERS_PATH"
trap - EXIT

echo "Installed systemd sudoers policy at $SUDOERS_PATH for user $DEPLOY_USER."
