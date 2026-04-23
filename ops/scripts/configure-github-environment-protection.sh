#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
ENVIRONMENT="${2:-}"
REVIEWER_IDS_ARGUMENT="${3:-${VELMIX_ENVIRONMENT_REVIEWER_IDS:-}}"
PREVENT_SELF_REVIEW="${VELMIX_PREVENT_SELF_REVIEW:-false}"
WAIT_TIMER="${VELMIX_ENVIRONMENT_WAIT_TIMER:-0}"

if [[ -z "$REPOSITORY" || -z "$ENVIRONMENT" || -z "$REVIEWER_IDS_ARGUMENT" ]]; then
  echo "Usage: configure-github-environment-protection.sh <owner/repo> <environment> <reviewer-id[,reviewer-id...]>" >&2
  exit 1
fi

reviewers_json() {
  local csv="$1"
  local reviewers=()
  IFS=',' read -r -a reviewers <<< "$csv"

  printf '['

  local first=true
  local reviewer_id=""
  for reviewer_id in "${reviewers[@]}"; do
    reviewer_id="${reviewer_id//[[:space:]]/}"

    if [[ -z "$reviewer_id" ]]; then
      continue
    fi

    if [[ "$first" == true ]]; then
      first=false
    else
      printf ','
    fi

    printf '{"type":"User","id":%s}' "$reviewer_id"
  done

  printf ']'
}

REVIEWERS_JSON="$(reviewers_json "$REVIEWER_IDS_ARGUMENT")"

if [[ "$REVIEWERS_JSON" == "[]" ]]; then
  echo "At least one numeric reviewer id is required." >&2
  exit 1
fi

gh api \
  --method PUT \
  -H "Accept: application/vnd.github+json" \
  "repos/${REPOSITORY}/environments/${ENVIRONMENT}" \
  --input - <<EOF
{
  "wait_timer": ${WAIT_TIMER},
  "prevent_self_review": ${PREVENT_SELF_REVIEW},
  "reviewers": ${REVIEWERS_JSON}
}
EOF
