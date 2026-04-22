#!/usr/bin/env bash
set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-$(pwd)}"
RELEASE="${VELMIX_RELEASE_IDENTIFIER:-}"
LOCAL_EVIDENCE_DIR="${VELMIX_DEPLOY_EVIDENCE_DIR:-$APP_PATH/storage/app/evidence-governed-deploy}"
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
REMOTE_DEPLOY_EVIDENCE_DIR="${VELMIX_REMOTE_DEPLOY_EVIDENCE_DIR:-$REMOTE_SHARED_PATH/evidence-governed-deploy/$RELEASE}"
REMOTE_ARCHIVE_NAME="${VELMIX_REMOTE_ARCHIVE_NAME:-$RELEASE.tar.gz}"
REMOTE_ARCHIVE_PATH="$REMOTE_TMP_PATH/$REMOTE_ARCHIVE_NAME"
LOCAL_ARCHIVE_PATH="${RUNNER_TEMP:-$APP_PATH/storage/app}/$REMOTE_ARCHIVE_NAME"
REMOTE_BOOTSTRAP_SCRIPT="${VELMIX_REMOTE_BOOTSTRAP_SCRIPT:-$APP_PATH/ops/scripts/bootstrap-remote-host-over-ssh.sh}"

required_vars=(
  RELEASE
  LOCAL_EVIDENCE_DIR
  REMOTE_HOST
  REMOTE_USER
  REMOTE_APP_ROOT
  VELMIX_DEPLOY_EVIDENCE
  VELMIX_ROLLBACK_EVIDENCE
  VELMIX_APPROVAL_EVIDENCE
  VELMIX_CUTOVER_EVIDENCE
  VELMIX_BACKUP_ARTIFACT
  VELMIX_RESTORE_EVIDENCE
  VELMIX_SSH_KNOWN_HOSTS
)

for name in "${required_vars[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "Missing required variable: $name" >&2
    exit 1
  fi
done

if ! command -v ssh >/dev/null 2>&1 || ! command -v scp >/dev/null 2>&1 || ! command -v git >/dev/null 2>&1; then
  echo "This script requires ssh, scp, and git on the runner." >&2
  exit 1
fi

quote() {
  printf '%q' "$1"
}

mkdir -p "$LOCAL_EVIDENCE_DIR" "$(dirname "$LOCAL_ARCHIVE_PATH")"
rm -f "$LOCAL_ARCHIVE_PATH"
git -C "$APP_PATH" archive --format=tar.gz --output="$LOCAL_ARCHIVE_PATH" HEAD

bash "$REMOTE_BOOTSTRAP_SCRIPT" | tee "$LOCAL_EVIDENCE_DIR/remote-bootstrap.json"

REMOTE_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
SCP_OPTS=(-P "$REMOTE_PORT" -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")
SSH_OPTS=(-p "$REMOTE_PORT" -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$HOME/.ssh/known_hosts")

scp "${SCP_OPTS[@]}" "$LOCAL_ARCHIVE_PATH" "${REMOTE_TARGET}:${REMOTE_ARCHIVE_PATH}"

remote_env=(
  "RELEASE=$(quote "$RELEASE")"
  "REMOTE_ARCHIVE_PATH=$(quote "$REMOTE_ARCHIVE_PATH")"
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
  "REMOTE_DEPLOY_EVIDENCE_DIR=$(quote "$REMOTE_DEPLOY_EVIDENCE_DIR")"
  "VELMIX_RELEASE_IDENTIFIER=$(quote "$RELEASE")"
  "VELMIX_DEPLOY_EVIDENCE=$(quote "${VELMIX_DEPLOY_EVIDENCE:-}")"
  "VELMIX_ROLLBACK_EVIDENCE=$(quote "${VELMIX_ROLLBACK_EVIDENCE:-}")"
  "VELMIX_APPROVAL_EVIDENCE=$(quote "${VELMIX_APPROVAL_EVIDENCE:-}")"
  "VELMIX_CUTOVER_EVIDENCE=$(quote "${VELMIX_CUTOVER_EVIDENCE:-}")"
  "VELMIX_BACKUP_ARTIFACT=$(quote "${VELMIX_BACKUP_ARTIFACT:-}")"
  "VELMIX_RESTORE_EVIDENCE=$(quote "${VELMIX_RESTORE_EVIDENCE:-}")"
  "VELMIX_SMOKE_EVIDENCE=$(quote "${VELMIX_SMOKE_EVIDENCE:-}")"
  "VELMIX_MONITORING_EVIDENCE=$(quote "${VELMIX_MONITORING_EVIDENCE:-}")"
  "VELMIX_DEPLOY_OPERATOR=$(quote "${VELMIX_DEPLOY_OPERATOR:-workflow-bot}")"
  "VELMIX_DEPLOY_NOTES=$(quote "${VELMIX_DEPLOY_NOTES:-Remote evidence-governed deployment workflow execution}")"
  "VELMIX_DEPLOY_ALLOW_WARNING=$(quote "${VELMIX_DEPLOY_ALLOW_WARNING:-false}")"
  "VELMIX_BACKUP_DRIVER=$(quote "${VELMIX_BACKUP_DRIVER:-remote-ssh}")"
  "VELMIX_BACKUP_CHECKSUM=$(quote "${VELMIX_BACKUP_CHECKSUM:-sha256:remote-archive}")"
  "VELMIX_BACKUP_SIZE=$(quote "${VELMIX_BACKUP_SIZE:-0}")"
)

deploy_exit=0

ssh "${SSH_OPTS[@]}" "$REMOTE_TARGET" "${remote_env[*]} bash -s" <<'EOF' || deploy_exit=$?
set -euo pipefail

RELEASE_PATH="$REMOTE_RELEASES_PATH/$RELEASE"
CURRENT_PATH="$REMOTE_APP_ROOT/current"
PROMOTED=false

rollback_on_error() {
  if [[ "$PROMOTED" == "true" && -x "$CURRENT_PATH/ops/scripts/rollback-to-previous-release.sh" ]]; then
    VELMIX_APP_ROOT="$REMOTE_APP_ROOT" \
    VELMIX_SHARED_PATH="$REMOTE_SHARED_PATH" \
    VELMIX_ENV_FILE="$REMOTE_ENV_FILE" \
    VELMIX_PHP_BIN="$REMOTE_PHP_BIN" \
    VELMIX_SYSTEMD_TARGET="$REMOTE_SYSTEMD_TARGET" \
    VELMIX_SYSTEMD_QUEUE_RESTART_SERVICE="$REMOTE_QUEUE_RESTART_SERVICE" \
    VELMIX_USE_SYSTEMD="$REMOTE_USE_SYSTEMD" \
      bash "$CURRENT_PATH/ops/scripts/rollback-to-previous-release.sh" || true
  fi
}

trap rollback_on_error ERR

mkdir -p "$REMOTE_TMP_PATH" "$REMOTE_RELEASES_PATH" "$REMOTE_DEPLOY_EVIDENCE_DIR"
rm -rf "$RELEASE_PATH"
mkdir -p "$RELEASE_PATH"
tar -xzf "$REMOTE_ARCHIVE_PATH" -C "$RELEASE_PATH"
chmod +x "$RELEASE_PATH"/ops/scripts/*.sh || true

export VELMIX_APP_ROOT="$REMOTE_APP_ROOT"
export VELMIX_RELEASES_PATH="$REMOTE_RELEASES_PATH"
export VELMIX_SHARED_PATH="$REMOTE_SHARED_PATH"
export VELMIX_ENV_FILE="$REMOTE_ENV_FILE"
export VELMIX_PHP_BIN="$REMOTE_PHP_BIN"
export VELMIX_COMPOSER_BIN="$REMOTE_COMPOSER_BIN"
export VELMIX_SYSTEMD_TARGET="$REMOTE_SYSTEMD_TARGET"
export VELMIX_SYSTEMD_QUEUE_RESTART_SERVICE="$REMOTE_QUEUE_RESTART_SERVICE"
export VELMIX_USE_SYSTEMD="$REMOTE_USE_SYSTEMD"

if [[ "$REMOTE_INSTALL_UNITS" == "true" ]]; then
  bash "$RELEASE_PATH/ops/scripts/install-systemd-units.sh"
fi

bash "$RELEASE_PATH/ops/scripts/prepare-release.sh" "$RELEASE_PATH"
bash "$RELEASE_PATH/ops/scripts/promote-release.sh" "$RELEASE_PATH"
PROMOTED=true

export VELMIX_APP_PATH="$CURRENT_PATH"
export VELMIX_DEPLOY_EVIDENCE_DIR="$REMOTE_DEPLOY_EVIDENCE_DIR"

bash "$CURRENT_PATH/ops/scripts/check-backend-health.sh" > "$REMOTE_DEPLOY_EVIDENCE_DIR/backend-health.txt"
bash "$CURRENT_PATH/ops/scripts/run-evidence-governed-deploy.sh"

rm -f "$REMOTE_ARCHIVE_PATH"
trap - ERR
EOF

scp "${SCP_OPTS[@]}" -r "${REMOTE_TARGET}:${REMOTE_DEPLOY_EVIDENCE_DIR}/." "$LOCAL_EVIDENCE_DIR/" || true

if [[ "$deploy_exit" -ne 0 ]]; then
  echo "Remote deploy over SSH failed with exit code $deploy_exit" >&2
  exit "$deploy_exit"
fi

echo "Remote deploy completed successfully for release $RELEASE"
