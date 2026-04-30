set -euo pipefail

APP_PATH="${VELMIX_APP_PATH:-$(pwd)}"
PHP_BIN="${VELMIX_PHP_BIN:-php}"
EVIDENCE_DIR="${VELMIX_DEPLOY_EVIDENCE_DIR:-$APP_PATH/storage/app/deploy-evidence}"
RELEASE="${VELMIX_RELEASE_IDENTIFIER:-}"
DEPLOY_EVIDENCE="${VELMIX_DEPLOY_EVIDENCE:-}"
ROLLBACK_EVIDENCE="${VELMIX_ROLLBACK_EVIDENCE:-}"
APPROVAL_EVIDENCE="${VELMIX_APPROVAL_EVIDENCE:-}"
CUTOVER_EVIDENCE="${VELMIX_CUTOVER_EVIDENCE:-}"
BACKUP_ARTIFACT="${VELMIX_BACKUP_ARTIFACT:-}"
RESTORE_EVIDENCE="${VELMIX_RESTORE_EVIDENCE:-}"
SMOKE_EVIDENCE="${VELMIX_SMOKE_EVIDENCE:-}"
MONITORING_EVIDENCE="${VELMIX_MONITORING_EVIDENCE:-}"
OPERATOR="${VELMIX_DEPLOY_OPERATOR:-workflow-bot}"
NOTES="${VELMIX_DEPLOY_NOTES:-Evidence-governed deployment workflow execution}"
ALLOW_WARNING="${VELMIX_DEPLOY_ALLOW_WARNING:-false}"
BACKUP_CHECKSUM="${VELMIX_BACKUP_CHECKSUM:-sha256:workflow-placeholder}"
BACKUP_SIZE="${VELMIX_BACKUP_SIZE:-0}"
BACKUP_DRIVER="${VELMIX_BACKUP_DRIVER:-workflow-simulated}"
TARGET_ENVIRONMENT="${VELMIX_TARGET_ENVIRONMENT:-staging}"
TOPOLOGY_MODE="${VELMIX_REMOTE_TOPOLOGY_MODE:-isolated}"
GOVERNANCE_MODE="${VELMIX_GOVERNANCE_MODE:-independent-review}"

if [[ -z "$RELEASE" || -z "$DEPLOY_EVIDENCE" || -z "$ROLLBACK_EVIDENCE" || -z "$APPROVAL_EVIDENCE" || -z "$CUTOVER_EVIDENCE" || -z "$BACKUP_ARTIFACT" || -z "$RESTORE_EVIDENCE" ]]; then
  echo "Missing mandatory evidence variables. Required: VELMIX_RELEASE_IDENTIFIER, VELMIX_DEPLOY_EVIDENCE, VELMIX_ROLLBACK_EVIDENCE, VELMIX_APPROVAL_EVIDENCE, VELMIX_CUTOVER_EVIDENCE, VELMIX_BACKUP_ARTIFACT, VELMIX_RESTORE_EVIDENCE." >&2
  exit 1
fi

mkdir -p "$EVIDENCE_DIR"
cd "$APP_PATH"

run_json() {
  local name="$1"
  shift
  "$@" | tee "$EVIDENCE_DIR/${name}.json"
}

run_text() {
  local name="$1"
  shift
  "$@" | tee "$EVIDENCE_DIR/${name}.txt"
}

write_skipped_json() {
  local name="$1"
  local reason="$2"

  cat > "$EVIDENCE_DIR/${name}.json" <<EOF
{
  "status": "skipped",
  "checked_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "reason": "$reason",
  "target_environment": "$TARGET_ENVIRONMENT",
  "release": "$RELEASE"
}
EOF
}

FAIL_OPTION="--fail-on-warning"
ALLOW_WARNING_OPTION=""

if [[ "$ALLOW_WARNING" == "true" ]]; then
  FAIL_OPTION="--fail-on-critical"
  ALLOW_WARNING_OPTION="--allow-warning"
fi

SINGLE_HOST_PRODUCTION_MODE=false

if [[ "$TARGET_ENVIRONMENT" == "production" && "$TOPOLOGY_MODE" == "single-host" && "$GOVERNANCE_MODE" == "single-operator" ]]; then
  SINGLE_HOST_PRODUCTION_MODE=true
fi

run_json backup_record "$PHP_BIN" artisan system:record-backup \
  "$BACKUP_ARTIFACT" \
  --checksum="$BACKUP_CHECKSUM" \
  --size="$BACKUP_SIZE" \
  --driver="$BACKUP_DRIVER" \
  --json

run_json preflight "$PHP_BIN" artisan system:preflight --json "$FAIL_OPTION"
run_json alerts "$PHP_BIN" artisan system:alerts --json
run_json backup_readiness "$PHP_BIN" artisan system:backup-readiness --json "$FAIL_OPTION"
run_json restore_drill "$PHP_BIN" artisan system:restore-drill --json "$FAIL_OPTION"

STAGING_ARGS=(
  "$APP_PATH/ops/scripts/certify-staging-release.sh"
  "$RELEASE"
  "$DEPLOY_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  "$SMOKE_EVIDENCE"
  "$BACKUP_ARTIFACT"
  "$OPERATOR"
)

if [[ -n "$ALLOW_WARNING_OPTION" ]]; then
  STAGING_ARGS+=("$ALLOW_WARNING_OPTION")
fi

if [[ "$SINGLE_HOST_PRODUCTION_MODE" == "true" ]]; then
  STAGING_ENV=(
    env
    VELMIX_STAGING_CERTIFICATION_ENV=production
    VELMIX_STAGING_CERTIFICATION_REQUIRED_ENVS=production
  )

  run_json staging_record "${STAGING_ENV[@]}" bash "${STAGING_ARGS[@]}"
  run_json staging_summary "${STAGING_ENV[@]}" "$PHP_BIN" artisan system:staging-certification --json "$FAIL_OPTION"
elif [[ "$TARGET_ENVIRONMENT" == "production" ]]; then
  write_skipped_json staging_record staging_record_reused_for_production_cutover
  write_skipped_json staging_summary staging_summary_reused_for_production_cutover
else
  run_json staging_record bash "${STAGING_ARGS[@]}"
  run_json staging_summary "$PHP_BIN" artisan system:staging-certification --json "$FAIL_OPTION"
fi

PROMOTION_ARGS=(
  "$APP_PATH/ops/scripts/record-release-promotion.sh"
  "$RELEASE"
  "$APPROVAL_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  "$OPERATOR"
  "$NOTES"
)

if [[ -n "$ALLOW_WARNING_OPTION" ]]; then
  PROMOTION_ARGS+=("$ALLOW_WARNING_OPTION")
fi

if [[ "$SINGLE_HOST_PRODUCTION_MODE" == "true" ]]; then
  PROMOTION_ENV=(
    env
    VELMIX_STAGING_CERTIFICATION_ENV=production
    VELMIX_STAGING_CERTIFICATION_REQUIRED_ENVS=production
    VELMIX_RELEASE_PROMOTION_ENV=production
    VELMIX_RELEASE_PROMOTION_REQUIRED_ENVS=production
  )

  run_json promotion_record "${PROMOTION_ENV[@]}" bash "${PROMOTION_ARGS[@]}"
  run_json promotion_summary "${PROMOTION_ENV[@]}" "$PHP_BIN" artisan system:promotion-readiness --json "$FAIL_OPTION"
elif [[ "$TARGET_ENVIRONMENT" == "production" ]]; then
  write_skipped_json promotion_record promotion_record_reused_for_production_cutover
  write_skipped_json promotion_summary promotion_summary_reused_for_production_cutover
else
  run_json promotion_record bash "${PROMOTION_ARGS[@]}"
  run_json promotion_summary "$PHP_BIN" artisan system:promotion-readiness --json "$FAIL_OPTION"
fi

CUTOVER_ARGS=(
  "$APP_PATH/ops/scripts/record-release-cutover.sh"
  "$RELEASE"
  "$CUTOVER_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  "$MONITORING_EVIDENCE"
  "$OPERATOR"
  "$NOTES"
)

if [[ -n "$ALLOW_WARNING_OPTION" ]]; then
  CUTOVER_ARGS+=("$ALLOW_WARNING_OPTION")
fi

run_json cutover_record bash "${CUTOVER_ARGS[@]}"
run_json cutover_summary "$PHP_BIN" artisan system:cutover-readiness --json "$FAIL_OPTION"

OPERATIONAL_ARGS=(
  "$PHP_BIN"
  artisan
  system:record-operational-certification
  "$RELEASE"
  "$DEPLOY_EVIDENCE"
  "$ROLLBACK_EVIDENCE"
  "$BACKUP_ARTIFACT"
  "$RESTORE_EVIDENCE"
  --json
  --operator="$OPERATOR"
  --notes="$NOTES"
)

if [[ -n "$MONITORING_EVIDENCE" ]]; then
  OPERATIONAL_ARGS+=("--monitoring-evidence=$MONITORING_EVIDENCE")
fi

if [[ -n "$ALLOW_WARNING_OPTION" ]]; then
  OPERATIONAL_ARGS+=("$ALLOW_WARNING_OPTION")
fi

run_json operational_record "${OPERATIONAL_ARGS[@]}"
run_json operational_summary "$PHP_BIN" artisan system:operational-certification --json "$FAIL_OPTION"
run_json observability "$PHP_BIN" artisan system:observability-report --json
run_text schedule "$PHP_BIN" artisan schedule:list

cat > "$EVIDENCE_DIR/manifest.json" <<EOF
{
  "release": "$RELEASE",
  "operator": "$OPERATOR",
  "allow_warning": "$ALLOW_WARNING",
  "evidence_dir": "$EVIDENCE_DIR",
  "deploy_evidence": "$DEPLOY_EVIDENCE",
  "rollback_evidence": "$ROLLBACK_EVIDENCE",
  "approval_evidence": "$APPROVAL_EVIDENCE",
  "cutover_evidence": "$CUTOVER_EVIDENCE",
  "backup_artifact": "$BACKUP_ARTIFACT",
  "restore_evidence": "$RESTORE_EVIDENCE",
  "monitoring_evidence": "$MONITORING_EVIDENCE"
}
EOF

cat > "$EVIDENCE_DIR/summary.md" <<EOF
# Evidence Governed Deployment

- Release: \`$RELEASE\`
- Operator: \`$OPERATOR\`
- Allow warning override: \`$ALLOW_WARNING\`
- Evidence directory: \`$EVIDENCE_DIR\`
- Generated files:
  - \`preflight.json\`
  - \`alerts.json\`
  - \`backup_record.json\`
  - \`backup_readiness.json\`
  - \`restore_drill.json\`
  - \`staging_record.json\`
  - \`staging_summary.json\`
  - \`promotion_record.json\`
  - \`promotion_summary.json\`
  - \`cutover_record.json\`
  - \`cutover_summary.json\`
  - \`operational_record.json\`
  - \`operational_summary.json\`
  - \`observability.json\`
  - \`schedule.txt\`
  - \`manifest.json\`
EOF

echo "Evidence-governed deployment chain completed successfully."
