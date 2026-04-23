#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
STAGING_ENV="${2:-staging}"
PRODUCTION_ENV="${3:-production}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -z "$REPOSITORY" ]]; then
  echo "Usage: check-production-go-no-go.sh <owner/repo> [staging-environment] [production-environment]" >&2
  exit 1
fi

if ! command -v git >/dev/null 2>&1; then
  echo "git is required to inspect branch and worktree state." >&2
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "php is required to parse readiness payloads." >&2
  exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
worktree_clean=true

if [[ -n "$(git status --short 2>/dev/null)" ]]; then
  worktree_clean=false
fi

readiness_script="$SCRIPT_DIR/check-github-environment-readiness.sh"

staging_payload="$(
  VELMIX_MIN_REQUIRED_REVIEWERS=1 \
  VELMIX_FAIL_ON_SELF_REVIEW=false \
  VELMIX_FAIL_ON_ADMIN_BYPASS=false \
  "$readiness_script" "$REPOSITORY" "$STAGING_ENV" 2>/dev/null || true
)"
production_payload="$(
  VELMIX_MIN_REQUIRED_REVIEWERS=2 \
  VELMIX_FAIL_ON_SELF_REVIEW=true \
  VELMIX_FAIL_ON_ADMIN_BYPASS=true \
  "$readiness_script" "$REPOSITORY" "$PRODUCTION_ENV" 2>/dev/null || true
)"

if [[ -z "$staging_payload" ]]; then
  staging_payload='{"status":"blocked","issues":["staging_readiness_failed"]}'
fi

if [[ -z "$production_payload" ]]; then
  production_payload='{"status":"blocked","issues":["production_readiness_failed"]}'
fi

json_status() {
  php -r '$data = json_decode(stream_get_contents(STDIN), true); echo $data["status"] ?? "blocked";'
}

staging_status="$(printf '%s' "$staging_payload" | json_status)"
production_status="$(printf '%s' "$production_payload" | json_status)"

status="ok"

if [[ "$worktree_clean" != "true" || "$staging_status" == "blocked" || "$production_status" == "blocked" ]]; then
  status="blocked"
elif [[ "$staging_status" == "warning" || "$production_status" == "warning" ]]; then
  status="warning"
fi

printf '{\n'
printf '  "status": "%s",\n' "$status"
printf '  "repository": "%s",\n' "$REPOSITORY"
printf '  "branch": "%s",\n' "$branch"
printf '  "worktree_clean": %s,\n' "$worktree_clean"
printf '  "release_candidate": {\n'
printf '    "workflow_file": ".github/workflows/evidence-governed-deploy.yml",\n'
printf '    "local_quality_gate": "composer run velmix:ci",\n'
printf '    "mysql_quality_gate": "composer run velmix:ci:mysql"\n'
printf '  },\n'
printf '  "environments": {\n'
printf '    "staging": %s,\n' "$staging_payload"
printf '    "production": %s\n' "$production_payload"
printf '  }\n'
printf '}\n'

if [[ "$status" == "blocked" ]]; then
  exit 1
fi
