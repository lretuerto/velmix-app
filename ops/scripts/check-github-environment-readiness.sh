#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
ENVIRONMENT="${2:-}"
FAIL_ON_WARNING="${VELMIX_FAIL_ON_WARNING:-false}"
MIN_REQUIRED_REVIEWERS="${VELMIX_MIN_REQUIRED_REVIEWERS:-1}"
FAIL_ON_SELF_REVIEW="${VELMIX_FAIL_ON_SELF_REVIEW:-false}"
FAIL_ON_ADMIN_BYPASS="${VELMIX_FAIL_ON_ADMIN_BYPASS:-false}"

required_secrets=(
  VELMIX_SSH_HOST
  VELMIX_SSH_USER
  VELMIX_SSH_PRIVATE_KEY
  VELMIX_SSH_KNOWN_HOSTS
)

recommended_variables=(
  VELMIX_REMOTE_TOPOLOGY_ID
  VELMIX_REMOTE_TOPOLOGY_MODE
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

if [[ -z "$REPOSITORY" || -z "$ENVIRONMENT" ]]; then
  echo "Usage: check-github-environment-readiness.sh <owner/repo> <environment>" >&2
  exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required to inspect GitHub environments." >&2
  exit 1
fi

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

windows_path_value() {
  local value="$1"

  [[ "$value" =~ ^[A-Za-z]:[\\/] ]]
}

if ! gh api "repos/${REPOSITORY}/environments/${ENVIRONMENT}" >/dev/null 2>&1; then
  printf '{\n'
  printf '  "status": "blocked",\n'
  printf '  "repository": "%s",\n' "$REPOSITORY"
  printf '  "environment": "%s",\n' "$ENVIRONMENT"
  printf '  "exists": false,\n'
  printf '  "policy": {\n'
  printf '    "min_required_reviewers": %s,\n' "$MIN_REQUIRED_REVIEWERS"
  printf '    "fail_on_self_review": %s,\n' "$FAIL_ON_SELF_REVIEW"
  printf '    "fail_on_admin_bypass": %s\n' "$FAIL_ON_ADMIN_BYPASS"
  printf '  },\n'
  printf '  "required_reviewers": {\n'
  printf '    "count": 0,\n'
  printf '    "prevent_self_review": false,\n'
  printf '    "reviewers": []\n'
  printf '  },\n'
  printf '  "bypass": {\n'
  printf '    "can_admins_bypass": false\n'
  printf '  },\n'
  printf '  "secrets": {\n'
  printf '    "required": %s,\n' "$(json_array "${required_secrets[@]}")"
  printf '    "configured": [],\n'
  printf '    "missing": %s\n' "$(json_array "${required_secrets[@]}")"
  printf '  },\n'
  printf '  "variables": {\n'
  printf '    "recommended": %s,\n' "$(json_array "${recommended_variables[@]}")"
  printf '    "configured": [],\n'
  printf '    "missing": %s,\n' "$(json_array "${recommended_variables[@]}")"
  printf '    "invalid": []\n'
  printf '  },\n'
  printf '  "issues": ["environment_missing"]\n'
  printf '}\n'
  exit 1
fi

mapfile -t configured_secrets < <(gh secret list --env "$ENVIRONMENT" -R "$REPOSITORY" --json name --jq '.[].name')
mapfile -t configured_variable_records < <(gh variable list --env "$ENVIRONMENT" -R "$REPOSITORY" --json name,value --jq '.[] | "\(.name)=\(.value)"')
mapfile -t reviewer_logins < <(gh api "repos/${REPOSITORY}/environments/${ENVIRONMENT}" --jq '.protection_rules[]? | select(.type == "required_reviewers") | .reviewers[]?.reviewer.login')

can_admins_bypass="$(gh api "repos/${REPOSITORY}/environments/${ENVIRONMENT}" --jq '.can_admins_bypass')"
prevent_self_review="$(gh api "repos/${REPOSITORY}/environments/${ENVIRONMENT}" --jq '([.protection_rules[]? | select(.type == "required_reviewers") | .prevent_self_review] | first) // false')"
reviewer_count="${#reviewer_logins[@]}"

configured_variables=()
missing_secrets=()
missing_variables=()
invalid_variables=()
issues=()
status="ok"

for record in "${configured_variable_records[@]}"; do
  name="${record%%=*}"
  value="${record#*=}"
  configured_variables+=("$name")

  case "$name" in
    VELMIX_REMOTE_TOPOLOGY_MODE)
      if [[ "$value" != "isolated" && "$value" != "single-host" ]]; then
        invalid_variables+=("$name")
      fi
      ;;
    VELMIX_REMOTE_APP_ROOT|VELMIX_REMOTE_RELEASES_PATH|VELMIX_REMOTE_SHARED_PATH|VELMIX_REMOTE_ENV_FILE|VELMIX_REMOTE_TMP_PATH)
      if windows_path_value "$value"; then
        invalid_variables+=("$name")
      fi
      ;;
  esac
done

for name in "${required_secrets[@]}"; do
  if ! contains_name "$name" "${configured_secrets[@]}"; then
    missing_secrets+=("$name")
  fi
done

for name in "${recommended_variables[@]}"; do
  if ! contains_name "$name" "${configured_variables[@]}"; then
    missing_variables+=("$name")
  fi
done

if (( reviewer_count == 0 )); then
  issues+=("required_reviewers_missing")
  status="blocked"
fi

if (( reviewer_count < MIN_REQUIRED_REVIEWERS )); then
  issues+=("insufficient_required_reviewers")
  status="blocked"
fi

if (( ${#missing_secrets[@]} > 0 )); then
  issues+=("missing_required_secrets")
  status="blocked"
fi

if [[ "$status" != "blocked" && ${#missing_variables[@]} -gt 0 ]]; then
  issues+=("missing_recommended_variables")
  status="warning"
fi

if (( ${#invalid_variables[@]} > 0 )); then
  issues+=("invalid_remote_variables")
  status="blocked"
fi

if [[ "$can_admins_bypass" == "true" ]]; then
  issues+=("admin_bypass_allowed")
  if [[ "$FAIL_ON_ADMIN_BYPASS" == "true" ]]; then
    status="blocked"
  elif [[ "$status" != "blocked" ]]; then
    status="warning"
  fi
fi

if [[ "$prevent_self_review" != "true" ]]; then
  issues+=("self_review_allowed")
  if [[ "$FAIL_ON_SELF_REVIEW" == "true" ]]; then
    status="blocked"
  elif [[ "$status" != "blocked" ]]; then
    status="warning"
  fi
fi

printf '{\n'
printf '  "status": "%s",\n' "$status"
printf '  "repository": "%s",\n' "$REPOSITORY"
printf '  "environment": "%s",\n' "$ENVIRONMENT"
printf '  "exists": true,\n'
printf '  "policy": {\n'
printf '    "min_required_reviewers": %s,\n' "$MIN_REQUIRED_REVIEWERS"
printf '    "fail_on_self_review": %s,\n' "$FAIL_ON_SELF_REVIEW"
printf '    "fail_on_admin_bypass": %s\n' "$FAIL_ON_ADMIN_BYPASS"
printf '  },\n'
printf '  "required_reviewers": {\n'
printf '    "count": %s,\n' "$reviewer_count"
printf '    "prevent_self_review": %s,\n' "$prevent_self_review"
printf '    "reviewers": %s\n' "$(json_array "${reviewer_logins[@]}")"
printf '  },\n'
printf '  "bypass": {\n'
printf '    "can_admins_bypass": %s\n' "$can_admins_bypass"
printf '  },\n'
printf '  "secrets": {\n'
printf '    "required": %s,\n' "$(json_array "${required_secrets[@]}")"
printf '    "configured": %s,\n' "$(json_array "${configured_secrets[@]}")"
printf '    "missing": %s\n' "$(json_array "${missing_secrets[@]}")"
printf '  },\n'
printf '  "variables": {\n'
printf '    "recommended": %s,\n' "$(json_array "${recommended_variables[@]}")"
printf '    "configured": %s,\n' "$(json_array "${configured_variables[@]}")"
printf '    "missing": %s,\n' "$(json_array "${missing_variables[@]}")"
printf '    "invalid": %s\n' "$(json_array "${invalid_variables[@]}")"
printf '  },\n'
printf '  "issues": %s\n' "$(json_array "${issues[@]}")"
printf '}\n'

if [[ "$status" == "blocked" ]]; then
  exit 1
fi

if [[ "$status" == "warning" && "$FAIL_ON_WARNING" == "true" ]]; then
  exit 1
fi
