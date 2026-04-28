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

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required to inspect environment topology metadata." >&2
  exit 1
fi

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

branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'unknown')"
worktree_clean=true

if [[ -n "$(git status --short 2>/dev/null)" ]]; then
  worktree_clean=false
fi

get_environment_variable() {
  local environment="$1"
  local variable_name="$2"

  gh variable list --env "$environment" -R "$REPOSITORY" --json name,value --jq ".[] | select(.name == \"$variable_name\") | .value" 2>/dev/null || true
}

readiness_script="$SCRIPT_DIR/check-github-environment-readiness.sh"
status="ok"
issues=()

staging_topology_id="$(get_environment_variable "$STAGING_ENV" "VELMIX_REMOTE_TOPOLOGY_ID")"
production_topology_id="$(get_environment_variable "$PRODUCTION_ENV" "VELMIX_REMOTE_TOPOLOGY_ID")"
staging_topology_mode="$(get_environment_variable "$STAGING_ENV" "VELMIX_REMOTE_TOPOLOGY_MODE")"
production_topology_mode="$(get_environment_variable "$PRODUCTION_ENV" "VELMIX_REMOTE_TOPOLOGY_MODE")"
production_governance_mode="$(get_environment_variable "$PRODUCTION_ENV" "VELMIX_GOVERNANCE_MODE")"
topology_isolated=false
shared_topology_allowed=false
single_operator_governance_allowed=false
production_min_required_reviewers=2
production_fail_on_self_review=true
production_fail_on_admin_bypass=true

if [[ -z "$staging_topology_id" ]]; then
  issues+=("staging_topology_id_missing")
  status="blocked"
fi

if [[ -z "$production_topology_id" ]]; then
  issues+=("production_topology_id_missing")
  status="blocked"
fi

if [[ -z "$staging_topology_mode" ]]; then
  issues+=("staging_topology_mode_missing")
  status="blocked"
fi

if [[ -z "$production_topology_mode" ]]; then
  issues+=("production_topology_mode_missing")
  status="blocked"
fi

if [[ -z "$production_governance_mode" ]]; then
  issues+=("production_governance_mode_missing")
  status="blocked"
elif [[ "$production_governance_mode" == "single-operator" ]]; then
  single_operator_governance_allowed=true
  production_min_required_reviewers=1
  production_fail_on_self_review=false
  production_fail_on_admin_bypass=false
  issues+=("single_operator_governance_acknowledged")
  if [[ "$status" != "blocked" ]]; then
    status="warning"
  fi
fi

staging_payload="$(
  VELMIX_MIN_REQUIRED_REVIEWERS=1 \
  VELMIX_FAIL_ON_SELF_REVIEW=false \
  VELMIX_FAIL_ON_ADMIN_BYPASS=false \
  "$readiness_script" "$REPOSITORY" "$STAGING_ENV" 2>/dev/null || true
)"
production_payload="$(
  VELMIX_MIN_REQUIRED_REVIEWERS="$production_min_required_reviewers" \
  VELMIX_FAIL_ON_SELF_REVIEW="$production_fail_on_self_review" \
  VELMIX_FAIL_ON_ADMIN_BYPASS="$production_fail_on_admin_bypass" \
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

if [[ -n "$staging_topology_id" && -n "$production_topology_id" ]]; then
  if [[ "$staging_topology_id" == "$production_topology_id" ]]; then
    if [[ "$staging_topology_mode" == "single-host" && "$production_topology_mode" == "single-host" ]]; then
      shared_topology_allowed=true
      issues+=("shared_topology_single_host_acknowledged")
      if [[ "$status" != "blocked" ]]; then
        status="warning"
      fi
    else
      issues+=("shared_topology_id_between_staging_and_production")
      status="blocked"
    fi
  else
    topology_isolated=true
  fi
fi

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
printf '  "topology": {\n'
printf '    "staging_topology_id": "%s",\n' "$staging_topology_id"
printf '    "production_topology_id": "%s",\n' "$production_topology_id"
printf '    "staging_topology_mode": "%s",\n' "$staging_topology_mode"
printf '    "production_topology_mode": "%s",\n' "$production_topology_mode"
printf '    "isolated": %s,\n' "$topology_isolated"
printf '    "shared_topology_allowed": %s\n' "$shared_topology_allowed"
printf '  },\n'
printf '  "governance": {\n'
printf '    "production_governance_mode": "%s",\n' "$production_governance_mode"
printf '    "single_operator_allowed": %s,\n' "$single_operator_governance_allowed"
printf '    "production_policy": {\n'
printf '      "min_required_reviewers": %s,\n' "$production_min_required_reviewers"
printf '      "fail_on_self_review": %s,\n' "$production_fail_on_self_review"
printf '      "fail_on_admin_bypass": %s\n' "$production_fail_on_admin_bypass"
printf '    }\n'
printf '  },\n'
printf '  "environments": {\n'
printf '    "staging": %s,\n' "$staging_payload"
printf '    "production": %s\n' "$production_payload"
printf '  },\n'
printf '  "issues": %s\n' "$(json_array "${issues[@]}")"
printf '}\n'

if [[ "$status" == "blocked" ]]; then
  exit 1
fi
