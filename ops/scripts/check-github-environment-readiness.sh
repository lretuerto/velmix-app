#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
ENVIRONMENT="${2:-}"
FAIL_ON_WARNING="${VELMIX_FAIL_ON_WARNING:-false}"

required_secrets=(
  VELMIX_SSH_HOST
  VELMIX_SSH_USER
  VELMIX_SSH_PRIVATE_KEY
  VELMIX_SSH_KNOWN_HOSTS
)

recommended_variables=(
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
status="ok"

for record in "${configured_variable_records[@]}"; do
  name="${record%%=*}"
  value="${record#*=}"
  configured_variables+=("$name")

  case "$name" in
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
  status="blocked"
fi

if (( ${#missing_secrets[@]} > 0 )); then
  status="blocked"
fi

if [[ "$status" != "blocked" && ${#missing_variables[@]} -gt 0 ]]; then
  status="warning"
fi

if (( ${#invalid_variables[@]} > 0 )); then
  status="blocked"
fi

if [[ "$status" != "blocked" && "$can_admins_bypass" == "true" ]]; then
  status="warning"
fi

if [[ "$status" != "blocked" && "$prevent_self_review" != "true" ]]; then
  status="warning"
fi

printf '{\n'
printf '  "status": "%s",\n' "$status"
printf '  "repository": "%s",\n' "$REPOSITORY"
printf '  "environment": "%s",\n' "$ENVIRONMENT"
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
printf '  }\n'
printf '}\n'

if [[ "$status" == "blocked" ]]; then
  exit 1
fi

if [[ "$status" == "warning" && "$FAIL_ON_WARNING" == "true" ]]; then
  exit 1
fi
