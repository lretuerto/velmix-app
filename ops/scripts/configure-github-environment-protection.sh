#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${1:-}"
ENVIRONMENT="${2:-}"
REVIEWER_ID="${3:-}"
PREVENT_SELF_REVIEW="${VELMIX_PREVENT_SELF_REVIEW:-false}"
WAIT_TIMER="${VELMIX_ENVIRONMENT_WAIT_TIMER:-0}"

if [[ -z "$REPOSITORY" || -z "$ENVIRONMENT" || -z "$REVIEWER_ID" ]]; then
  echo "Usage: configure-github-environment-protection.sh <owner/repo> <environment> <reviewer-id>" >&2
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
  "reviewers": [
    {
      "type": "User",
      "id": ${REVIEWER_ID}
    }
  ]
}
EOF
