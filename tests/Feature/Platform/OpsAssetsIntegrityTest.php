<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class OpsAssetsIntegrityTest extends TestCase
{
    public function test_versioned_ops_assets_exist_with_expected_release_controls(): void
    {
        $this->assertFileExists(base_path('ops/systemd/velmix-app.env.example'));
        $this->assertFileExists(base_path('ops/systemd/velmix-scheduler.service'));
        $this->assertFileExists(base_path('ops/systemd/velmix-queue-worker.service'));
        $this->assertFileExists(base_path('ops/systemd/velmix-queue-restart.service'));
        $this->assertFileExists(base_path('ops/systemd/velmix-backend.target'));
        $this->assertFileExists(base_path('ops/scripts/provision-ubuntu-node.sh'));
        $this->assertFileExists(base_path('ops/scripts/install-systemd-units.sh'));
        $this->assertFileExists(base_path('ops/scripts/install-deploy-systemd-sudoers.sh'));
        $this->assertFileExists(base_path('ops/scripts/enable-systemd-managed-node.sh'));
        $this->assertFileExists(base_path('ops/scripts/cutover-single-host-production.sh'));
        $this->assertFileExists(base_path('ops/scripts/systemctl-helpers.sh'));
        $this->assertFileExists(base_path('ops/scripts/bootstrap-shared-path.sh'));
        $this->assertFileExists(base_path('ops/scripts/prepare-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/promote-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/rollback-to-previous-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-backup-readiness.sh'));
        $this->assertFileExists(base_path('ops/scripts/record-backup-success.sh'));
        $this->assertFileExists(base_path('ops/scripts/run-restore-drill.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-staging-certification.sh'));
        $this->assertFileExists(base_path('ops/scripts/certify-staging-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-promotion-readiness.sh'));
        $this->assertFileExists(base_path('ops/scripts/record-release-promotion.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-cutover-readiness.sh'));
        $this->assertFileExists(base_path('ops/scripts/record-release-cutover.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-operational-certification.sh'));
        $this->assertFileExists(base_path('ops/scripts/record-operational-certification.sh'));
        $this->assertFileExists(base_path('ops/scripts/run-evidence-governed-deploy.sh'));
        $this->assertFileExists(base_path('ops/scripts/bootstrap-remote-host-over-ssh.sh'));
        $this->assertFileExists(base_path('ops/scripts/deploy-release-over-ssh.sh'));
        $this->assertFileExists(base_path('ops/scripts/configure-github-environment-protection.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-github-environment-readiness.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-production-go-no-go.sh'));
        $this->assertFileExists(base_path('ops/scripts/sync-github-environment-config.sh'));
        $this->assertFileExists(base_path('ops/github-environments/staging.env.example'));
        $this->assertFileExists(base_path('ops/github-environments/staging.variables.env.example'));
        $this->assertFileExists(base_path('ops/github-environments/production.env.example'));
        $this->assertFileExists(base_path('ops/github-environments/production.variables.env.example'));
        $this->assertFileExists(base_path('.github/workflows/evidence-governed-deploy.yml'));

        $envTemplate = file_get_contents(base_path('ops/systemd/velmix-app.env.example'));
        $this->assertIsString($envTemplate);
        $this->assertStringContainsString('VELMIX_CURRENT_LINK=/var/www/velmix/current', $envTemplate);
        $this->assertStringContainsString('VELMIX_PREVIOUS_LINK=/var/www/velmix/previous', $envTemplate);
        $this->assertStringContainsString('VELMIX_SYSTEMD_TARGET=velmix-backend.target', $envTemplate);
        $this->assertStringContainsString('VELMIX_BACKUP_ENABLED=true', $envTemplate);
        $this->assertStringContainsString('VELMIX_RESTORE_DRILL_PATH=/var/www/velmix/shared/restore-drills', $envTemplate);
        $this->assertStringContainsString('VELMIX_RELEASE_IDENTIFIER=release-2026-04-21-001', $envTemplate);
        $this->assertStringContainsString('VELMIX_STAGING_CERTIFICATION_STORAGE_PATH=/var/www/velmix/shared/staging-certifications', $envTemplate);
        $this->assertStringContainsString('VELMIX_RELEASE_PROMOTION_STORAGE_PATH=/var/www/velmix/shared/release-promotions', $envTemplate);
        $this->assertStringContainsString('VELMIX_RELEASE_CUTOVER_STORAGE_PATH=/var/www/velmix/shared/release-cutovers', $envTemplate);
        $this->assertStringContainsString('VELMIX_OPERATIONAL_CERTIFICATION_STORAGE_PATH=/var/www/velmix/shared/operational-certifications', $envTemplate);

        $schedulerUnit = file_get_contents(base_path('ops/systemd/velmix-scheduler.service'));
        $this->assertIsString($schedulerUnit);
        $this->assertStringContainsString('EnvironmentFile=-/etc/velmix/velmix.env', $schedulerUnit);
        $this->assertStringContainsString('Environment=APP_ENV=production', $schedulerUnit);
        $this->assertStringContainsString('ExecStartPre=/usr/bin/php artisan system:preflight --json --fail-on-critical', $schedulerUnit);
        $this->assertStringContainsString('WantedBy=velmix-backend.target', $schedulerUnit);
        $this->assertTrue(
            strpos($schedulerUnit, 'Environment=APP_ENV=production') < strpos($schedulerUnit, 'EnvironmentFile=-/etc/velmix/velmix.env'),
            'Scheduler unit should load the environment file after defaults so staging and production can override APP_ENV safely.'
        );

        $queueWorkerUnit = file_get_contents(base_path('ops/systemd/velmix-queue-worker.service'));
        $this->assertIsString($queueWorkerUnit);
        $this->assertStringContainsString('Environment=APP_ENV=production', $queueWorkerUnit);
        $this->assertStringContainsString('EnvironmentFile=-/etc/velmix/velmix.env', $queueWorkerUnit);
        $this->assertStringContainsString('ExecStart=/usr/bin/php artisan queue:work', $queueWorkerUnit);
        $this->assertStringContainsString('ExecReload=/usr/bin/php artisan queue:restart', $queueWorkerUnit);
        $this->assertStringContainsString('WantedBy=velmix-backend.target', $queueWorkerUnit);
        $this->assertTrue(
            strpos($queueWorkerUnit, 'Environment=VELMIX_QUEUE_MAX_TIME=3600') < strpos($queueWorkerUnit, 'EnvironmentFile=-/etc/velmix/velmix.env'),
            'Queue worker unit should load the environment file after queue defaults so per-environment overrides can take effect.'
        );

        $queueRestartUnit = file_get_contents(base_path('ops/systemd/velmix-queue-restart.service'));
        $this->assertIsString($queueRestartUnit);
        $this->assertStringContainsString('Environment=APP_ENV=production', $queueRestartUnit);
        $this->assertStringContainsString('EnvironmentFile=-/etc/velmix/velmix.env', $queueRestartUnit);
        $this->assertTrue(
            strpos($queueRestartUnit, 'Environment=APP_ENV=production') < strpos($queueRestartUnit, 'EnvironmentFile=-/etc/velmix/velmix.env'),
            'Queue restart unit should load the environment file after defaults so the target environment is not forced to production.'
        );

        $promoteScript = file_get_contents(base_path('ops/scripts/promote-release.sh'));
        $this->assertIsString($promoteScript);
        $this->assertStringContainsString('source "$SCRIPT_DIR/systemctl-helpers.sh"', $promoteScript);
        $this->assertStringContainsString('mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"', $promoteScript);
        $this->assertStringContainsString('VELMIX_DEPLOY_ALLOW_WARNING', $promoteScript);
        $this->assertStringContainsString('FAIL_OPTION="--fail-on-warning"', $promoteScript);
        $this->assertStringContainsString('FAIL_OPTION="--fail-on-critical"', $promoteScript);
        $this->assertStringContainsString('artisan system:preflight --json "$FAIL_OPTION"', $promoteScript);
        $this->assertStringContainsString('velmix_run_systemctl restart "$SYSTEMD_TARGET"', $promoteScript);

        $rollbackScript = file_get_contents(base_path('ops/scripts/rollback-to-previous-release.sh'));
        $this->assertIsString($rollbackScript);
        $this->assertStringContainsString('source "$SCRIPT_DIR/systemctl-helpers.sh"', $rollbackScript);
        $this->assertStringContainsString('artisan system:preflight --json --fail-on-critical', $rollbackScript);
        $this->assertStringContainsString('mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"', $rollbackScript);

        $installUnitsScript = file_get_contents(base_path('ops/scripts/install-systemd-units.sh'));
        $this->assertIsString($installUnitsScript);
        $this->assertStringContainsString('systemctl daemon-reload', $installUnitsScript);
        $this->assertStringContainsString('systemctl enable velmix-backend.target', $installUnitsScript);
        $this->assertStringContainsString('velmix-app.env.example', $installUnitsScript);
        $this->assertStringContainsString('VELMIX_SYSTEMD_SOURCE_ENV_FILE', $installUnitsScript);
        $this->assertStringContainsString('VELMIX_SYNC_SYSTEMD_ENV', $installUnitsScript);
        $this->assertStringContainsString('Synchronized environment file from $SOURCE_ENV_FILE to $SYSTEMD_ENV_FILE', $installUnitsScript);

        $provisionUbuntuNodeScript = file_get_contents(base_path('ops/scripts/provision-ubuntu-node.sh'));
        $this->assertIsString($provisionUbuntuNodeScript);
        $this->assertStringContainsString('This script must run as root.', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('This script currently supports Debian/Ubuntu hosts with apt-get.', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('apt-get install -y "${base_packages[@]}" "${php_packages[@]}"', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('systemctl enable --now "$service"', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('adduser --disabled-password --gecos "" "$DEPLOY_USER"', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('VELMIX_INIT_ENV_TEMPLATE', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('bootstrap-shared-path.sh', $provisionUbuntuNodeScript);
        $this->assertStringContainsString('ufw allow "${SSH_PORT}/tcp"', $provisionUbuntuNodeScript);

        $installSystemdSudoersScript = file_get_contents(base_path('ops/scripts/install-deploy-systemd-sudoers.sh'));
        $this->assertIsString($installSystemdSudoersScript);
        $this->assertStringContainsString('This script must run as root.', $installSystemdSudoersScript);
        $this->assertStringContainsString('visudo is required to validate the generated sudoers file.', $installSystemdSudoersScript);
        $this->assertStringContainsString('NOPASSWD: $SYSTEMCTL_BIN daemon-reload', $installSystemdSudoersScript);
        $this->assertStringContainsString('NOPASSWD: $SYSTEMCTL_BIN restart $SYSTEMD_TARGET', $installSystemdSudoersScript);
        $this->assertStringContainsString('NOPASSWD: $SYSTEMCTL_BIN start $QUEUE_RESTART_SERVICE', $installSystemdSudoersScript);
        $this->assertStringContainsString('NOPASSWD: $SYSTEMCTL_BIN status $SYSTEMD_TARGET', $installSystemdSudoersScript);
        $this->assertStringContainsString('"$VISUDO_BIN" -cf "$tmp_file"', $installSystemdSudoersScript);

        $enableManagedNodeScript = file_get_contents(base_path('ops/scripts/enable-systemd-managed-node.sh'));
        $this->assertIsString($enableManagedNodeScript);
        $this->assertStringContainsString('This script must run as root.', $enableManagedNodeScript);
        $this->assertStringContainsString('VELMIX_SYNC_SYSTEMD_ENV=true', $enableManagedNodeScript);
        $this->assertStringContainsString('chown root:"$SYSTEMD_ENV_GROUP" "$SYSTEMD_ENV_FILE"', $enableManagedNodeScript);
        $this->assertStringContainsString('systemctl enable --now "$SYSTEMD_TARGET"', $enableManagedNodeScript);
        $this->assertStringContainsString('bash "$HEALTH_SCRIPT"', $enableManagedNodeScript);

        $singleHostCutoverScript = file_get_contents(base_path('ops/scripts/cutover-single-host-production.sh'));
        $this->assertIsString($singleHostCutoverScript);
        $this->assertStringContainsString('This script must run as root.', $singleHostCutoverScript);
        $this->assertStringContainsString('upsert_env "APP_ENV" "production"', $singleHostCutoverScript);
        $this->assertStringContainsString('upsert_env "APP_URL" "$TARGET_APP_URL"', $singleHostCutoverScript);
        $this->assertStringContainsString('upsert_env "VELMIX_RELEASE_CUTOVER_ENV" "production"', $singleHostCutoverScript);
        $this->assertStringContainsString('upsert_env "VELMIX_OPERATIONAL_CERTIFICATION_ENV" "production"', $singleHostCutoverScript);
        $this->assertStringContainsString('cp "$SHARED_ENV_FILE" "$SHARED_ENV_BACKUP"', $singleHostCutoverScript);
        $this->assertStringContainsString('install -m 0640 "$SHARED_ENV_FILE" "$SYSTEMD_ENV_FILE"', $singleHostCutoverScript);
        $this->assertStringContainsString('systemctl restart "$SYSTEMD_TARGET"', $singleHostCutoverScript);
        $this->assertStringContainsString('Single-host production cutover completed successfully.', $singleHostCutoverScript);

        $bootstrapScript = file_get_contents(base_path('ops/scripts/bootstrap-shared-path.sh'));
        $this->assertIsString($bootstrapScript);
        $this->assertStringContainsString('VELMIX_BACKUP_STORAGE_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_RESTORE_DRILL_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_STAGING_CERTIFICATION_STORAGE_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_RELEASE_PROMOTION_STORAGE_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_RELEASE_CUTOVER_STORAGE_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_OPERATIONAL_CERTIFICATION_STORAGE_PATH', $bootstrapScript);

        $checkBackupScript = file_get_contents(base_path('ops/scripts/check-backup-readiness.sh'));
        $this->assertIsString($checkBackupScript);
        $this->assertStringContainsString('artisan system:backup-readiness --json --fail-on-warning', $checkBackupScript);

        $recordBackupScript = file_get_contents(base_path('ops/scripts/record-backup-success.sh'));
        $this->assertIsString($recordBackupScript);
        $this->assertStringContainsString('artisan system:record-backup', $recordBackupScript);

        $restoreDrillScript = file_get_contents(base_path('ops/scripts/run-restore-drill.sh'));
        $this->assertIsString($restoreDrillScript);
        $this->assertStringContainsString('artisan system:restore-drill --json --fail-on-warning', $restoreDrillScript);

        $checkStagingCertificationScript = file_get_contents(base_path('ops/scripts/check-staging-certification.sh'));
        $this->assertIsString($checkStagingCertificationScript);
        $this->assertStringContainsString('artisan system:staging-certification --json --fail-on-warning', $checkStagingCertificationScript);

        $certifyStagingReleaseScript = file_get_contents(base_path('ops/scripts/certify-staging-release.sh'));
        $this->assertIsString($certifyStagingReleaseScript);
        $this->assertStringContainsString('artisan system:record-staging-certification', $certifyStagingReleaseScript);

        $checkPromotionReadinessScript = file_get_contents(base_path('ops/scripts/check-promotion-readiness.sh'));
        $this->assertIsString($checkPromotionReadinessScript);
        $this->assertStringContainsString('artisan system:promotion-readiness --json --fail-on-warning', $checkPromotionReadinessScript);

        $recordReleasePromotionScript = file_get_contents(base_path('ops/scripts/record-release-promotion.sh'));
        $this->assertIsString($recordReleasePromotionScript);
        $this->assertStringContainsString('artisan system:record-release-promotion', $recordReleasePromotionScript);

        $checkCutoverReadinessScript = file_get_contents(base_path('ops/scripts/check-cutover-readiness.sh'));
        $this->assertIsString($checkCutoverReadinessScript);
        $this->assertStringContainsString('artisan system:cutover-readiness --json --fail-on-warning', $checkCutoverReadinessScript);

        $recordReleaseCutoverScript = file_get_contents(base_path('ops/scripts/record-release-cutover.sh'));
        $this->assertIsString($recordReleaseCutoverScript);
        $this->assertStringContainsString('artisan system:record-release-cutover', $recordReleaseCutoverScript);

        $checkOperationalCertificationScript = file_get_contents(base_path('ops/scripts/check-operational-certification.sh'));
        $this->assertIsString($checkOperationalCertificationScript);
        $this->assertStringContainsString('artisan system:operational-certification --json --fail-on-warning', $checkOperationalCertificationScript);

        $recordOperationalCertificationScript = file_get_contents(base_path('ops/scripts/record-operational-certification.sh'));
        $this->assertIsString($recordOperationalCertificationScript);
        $this->assertStringContainsString('system:record-operational-certification', $recordOperationalCertificationScript);
        $this->assertStringContainsString('--allow-warning', $recordOperationalCertificationScript);

        $remoteBootstrapScript = file_get_contents(base_path('ops/scripts/bootstrap-remote-host-over-ssh.sh'));
        $this->assertIsString($remoteBootstrapScript);
        $this->assertStringContainsString('remote_env_file_missing', $remoteBootstrapScript);
        $this->assertStringContainsString('REMOTE_TMP_PATH', $remoteBootstrapScript);
        $this->assertStringContainsString('systemctl cat "$REMOTE_SYSTEMD_TARGET"', $remoteBootstrapScript);
        $this->assertStringContainsString('remote_systemd_control_privileges_missing', $remoteBootstrapScript);
        $this->assertStringContainsString('sudo -n -l "$systemctl_bin" daemon-reload', $remoteBootstrapScript);

        $evidenceGovernedDeployScript = file_get_contents(base_path('ops/scripts/run-evidence-governed-deploy.sh'));
        $this->assertIsString($evidenceGovernedDeployScript);
        $this->assertStringContainsString('VELMIX_TARGET_ENVIRONMENT', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('system:record-backup', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('system:record-operational-certification', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('write_skipped_json()', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('staging_record_reused_for_production_cutover', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('staging_summary_reused_for_production_cutover', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('promotion_record_reused_for_production_cutover', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('promotion_summary_reused_for_production_cutover', $evidenceGovernedDeployScript);
        $this->assertStringContainsString('summary.md', $evidenceGovernedDeployScript);
        $this->assertTrue(
            strpos($evidenceGovernedDeployScript, 'system:record-backup') < strpos($evidenceGovernedDeployScript, 'system:preflight'),
            'Evidence-governed deploy should record backup evidence before preflight checks.'
        );

        $remoteDeployScript = file_get_contents(base_path('ops/scripts/deploy-release-over-ssh.sh'));
        $this->assertIsString($remoteDeployScript);
        $this->assertStringContainsString('git -C "$APP_PATH" archive', $remoteDeployScript);
        $this->assertStringContainsString('bootstrap-remote-host-over-ssh.sh', $remoteDeployScript);
        $this->assertStringContainsString('remote-bootstrap.json', $remoteDeployScript);
        $this->assertStringContainsString('ops/scripts/prepare-release.sh', $remoteDeployScript);
        $this->assertStringContainsString('ops/scripts/promote-release.sh', $remoteDeployScript);
        $this->assertStringContainsString('ops/scripts/run-evidence-governed-deploy.sh', $remoteDeployScript);
        $this->assertStringContainsString('rollback-to-previous-release.sh', $remoteDeployScript);
        $this->assertTrue(
            strpos($remoteDeployScript, 'bootstrap-remote-host-over-ssh.sh') < strpos($remoteDeployScript, 'scp "${SCP_OPTS[@]}" "$LOCAL_ARCHIVE_PATH"'),
            'Remote host bootstrap should run before transferring the release archive.'
        );

        $prepareReleaseScript = file_get_contents(base_path('ops/scripts/prepare-release.sh'));
        $this->assertIsString($prepareReleaseScript);
        $this->assertStringContainsString('VELMIX_DEPLOY_ALLOW_WARNING', $prepareReleaseScript);
        $this->assertStringContainsString('FAIL_OPTION="--fail-on-warning"', $prepareReleaseScript);
        $this->assertStringContainsString('FAIL_OPTION="--fail-on-critical"', $prepareReleaseScript);
        $this->assertStringContainsString('artisan system:preflight --json "$FAIL_OPTION"', $prepareReleaseScript);

        $configureEnvironmentScript = file_get_contents(base_path('ops/scripts/configure-github-environment-protection.sh'));
        $this->assertIsString($configureEnvironmentScript);
        $this->assertStringContainsString('repos/${REPOSITORY}/environments/${ENVIRONMENT}', $configureEnvironmentScript);
        $this->assertStringContainsString('"reviewers"', $configureEnvironmentScript);
        $this->assertStringContainsString('VELMIX_ENVIRONMENT_REVIEWER_IDS', $configureEnvironmentScript);
        $this->assertStringContainsString('reviewer-id[,reviewer-id...]', $configureEnvironmentScript);
        $this->assertStringContainsString('IFS=\',\' read -r -a reviewers', $configureEnvironmentScript);
        $this->assertStringContainsString('At least one numeric reviewer id is required.', $configureEnvironmentScript);

        $environmentReadinessScript = file_get_contents(base_path('ops/scripts/check-github-environment-readiness.sh'));
        $this->assertIsString($environmentReadinessScript);
        $this->assertStringContainsString('gh secret list --env', $environmentReadinessScript);
        $this->assertStringContainsString('gh variable list --env', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_ID', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_MODE', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_GOVERNANCE_MODE', $environmentReadinessScript);
        $this->assertStringContainsString('"environment_missing"', $environmentReadinessScript);
        $this->assertStringContainsString('"invalid"', $environmentReadinessScript);
        $this->assertStringContainsString('"required_reviewers"', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_MIN_REQUIRED_REVIEWERS', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_FAIL_ON_SELF_REVIEW', $environmentReadinessScript);
        $this->assertStringContainsString('VELMIX_FAIL_ON_ADMIN_BYPASS', $environmentReadinessScript);
        $this->assertStringContainsString('"insufficient_required_reviewers"', $environmentReadinessScript);
        $this->assertStringContainsString('"admin_bypass_allowed"', $environmentReadinessScript);
        $this->assertStringContainsString('"self_review_allowed"', $environmentReadinessScript);

        $goNoGoScript = file_get_contents(base_path('ops/scripts/check-production-go-no-go.sh'));
        $this->assertIsString($goNoGoScript);
        $this->assertStringContainsString('check-github-environment-readiness.sh', $goNoGoScript);
        $this->assertStringContainsString('"production"', $goNoGoScript);
        $this->assertStringContainsString('"release_candidate"', $goNoGoScript);
        $this->assertStringContainsString('production_min_required_reviewers=2', $goNoGoScript);
        $this->assertStringContainsString('production_fail_on_self_review=true', $goNoGoScript);
        $this->assertStringContainsString('production_fail_on_admin_bypass=true', $goNoGoScript);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_ID', $goNoGoScript);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_MODE', $goNoGoScript);
        $this->assertStringContainsString('VELMIX_GOVERNANCE_MODE', $goNoGoScript);
        $this->assertStringContainsString('shared_topology_id_between_staging_and_production', $goNoGoScript);
        $this->assertStringContainsString('shared_topology_single_host_acknowledged', $goNoGoScript);
        $this->assertStringContainsString('production_topology_id_missing', $goNoGoScript);
        $this->assertStringContainsString('production_topology_mode_missing', $goNoGoScript);
        $this->assertStringContainsString('production_governance_mode_missing', $goNoGoScript);
        $this->assertStringContainsString('single_operator_governance_acknowledged', $goNoGoScript);

        $syncEnvironmentScript = file_get_contents(base_path('ops/scripts/sync-github-environment-config.sh'));
        $this->assertIsString($syncEnvironmentScript);
        $this->assertStringContainsString('gh secret set', $syncEnvironmentScript);
        $this->assertStringContainsString('gh variable set', $syncEnvironmentScript);
        $this->assertStringContainsString('FILE:', $syncEnvironmentScript);
        $this->assertStringContainsString('MSYS_NO_PATHCONV=1', $syncEnvironmentScript);

        $stagingEnvironmentTemplate = file_get_contents(base_path('ops/github-environments/staging.env.example'));
        $this->assertIsString($stagingEnvironmentTemplate);
        $this->assertStringContainsString('VELMIX_SSH_PRIVATE_KEY=FILE:', $stagingEnvironmentTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_APP_ROOT=/var/www/velmix', $stagingEnvironmentTemplate);

        $stagingVariablesTemplate = file_get_contents(base_path('ops/github-environments/staging.variables.env.example'));
        $this->assertIsString($stagingVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_ID=staging-primary-node', $stagingVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_MODE=isolated', $stagingVariablesTemplate);
        $this->assertStringContainsString('VELMIX_GOVERNANCE_MODE=independent-review', $stagingVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_APP_ROOT=/var/www/velmix', $stagingVariablesTemplate);
        $this->assertStringNotContainsString('VELMIX_SSH_PRIVATE_KEY', $stagingVariablesTemplate);

        $productionEnvironmentTemplate = file_get_contents(base_path('ops/github-environments/production.env.example'));
        $this->assertIsString($productionEnvironmentTemplate);
        $this->assertStringContainsString('VELMIX_SSH_PRIVATE_KEY=FILE:', $productionEnvironmentTemplate);
        $this->assertStringContainsString('production.example.internal', $productionEnvironmentTemplate);

        $productionVariablesTemplate = file_get_contents(base_path('ops/github-environments/production.variables.env.example'));
        $this->assertIsString($productionVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_ID=production-primary-node', $productionVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_TOPOLOGY_MODE=isolated', $productionVariablesTemplate);
        $this->assertStringContainsString('VELMIX_GOVERNANCE_MODE=independent-review', $productionVariablesTemplate);
        $this->assertStringContainsString('VELMIX_REMOTE_APP_ROOT=/var/www/velmix', $productionVariablesTemplate);
        $this->assertStringNotContainsString('VELMIX_SSH_PRIVATE_KEY', $productionVariablesTemplate);

        $healthScript = file_get_contents(base_path('ops/scripts/check-backend-health.sh'));
        $this->assertIsString($healthScript);
        $this->assertStringContainsString('source "$SCRIPT_DIR/systemctl-helpers.sh"', $healthScript);
        $this->assertStringContainsString('artisan system:observability-report --json', $healthScript);
        $this->assertStringContainsString('artisan system:backup-readiness --json', $healthScript);
        $this->assertStringContainsString('artisan system:staging-certification --json', $healthScript);
        $this->assertStringContainsString('artisan system:promotion-readiness --json', $healthScript);
        $this->assertStringContainsString('artisan system:cutover-readiness --json', $healthScript);
        $this->assertStringContainsString('artisan system:operational-certification --json', $healthScript);

        $systemctlHelpersScript = file_get_contents(base_path('ops/scripts/systemctl-helpers.sh'));
        $this->assertIsString($systemctlHelpersScript);
        $this->assertStringContainsString('velmix_run_privileged', $systemctlHelpersScript);
        $this->assertStringContainsString('sudo -n "$@"', $systemctlHelpersScript);
        $this->assertStringContainsString('velmix_run_systemctl', $systemctlHelpersScript);
        $this->assertStringContainsString('velmix_systemctl_requires_privilege', $systemctlHelpersScript);

        $workflow = file_get_contents(base_path('.github/workflows/evidence-governed-deploy.yml'));
        $this->assertIsString($workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringContainsString('environment:', $workflow);
        $this->assertStringContainsString('ops/scripts/run-evidence-governed-deploy.sh', $workflow);
        $this->assertStringContainsString('ops/scripts/bootstrap-remote-host-over-ssh.sh', $workflow);
        $this->assertStringContainsString('deployment_strategy:', $workflow);
        $this->assertStringContainsString('ops/scripts/deploy-release-over-ssh.sh', $workflow);
        $this->assertStringContainsString('VELMIX_SSH_HOST', $workflow);
        $this->assertStringContainsString('VELMIX_SSH_PRIVATE_KEY', $workflow);
        $this->assertStringContainsString('ops/scripts/check-production-go-no-go.sh', $workflow);
        $this->assertStringContainsString('ops/github-environments/production.env.example', $workflow);
        $this->assertStringContainsString('vars.VELMIX_REMOTE_APP_ROOT', $workflow);
        $this->assertStringContainsString('VELMIX_TARGET_ENVIRONMENT: ${{ inputs.target_environment }}', $workflow);
        $this->assertStringContainsString('ops/scripts/check-github-environment-readiness.sh', $workflow);
        $this->assertStringContainsString('upload-artifact@v4', $workflow);
        $this->assertStringContainsString('FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true', $workflow);
        $this->assertStringContainsString('evidence-governed-deploy-', $workflow);
        $this->assertStringContainsString('LOG_STACK: single,daily_json', $workflow);
        $this->assertStringContainsString('Missing required ${{ inputs.target_environment }} environment secret: $secret_name', $workflow);
        $this->assertStringContainsString('GitHub Actions maps $secret_name into runtime variable $runtime_name', $workflow);
        $this->assertStringContainsString('\`VELMIX_SSH_HOST\`', $workflow);
        $this->assertStringContainsString('\`VELMIX_SSH_USER\`', $workflow);
        $this->assertStringContainsString('\`VELMIX_SSH_HOST -> VELMIX_REMOTE_HOST\`', $workflow);
        $this->assertStringContainsString('\`VELMIX_SSH_USER -> VELMIX_REMOTE_USER\`', $workflow);
        $this->assertStringNotContainsString('\`VELMIX_REMOTE_HOST\`', $workflow);
        $this->assertStringNotContainsString('\`VELMIX_REMOTE_USER\`', $workflow);
    }
}
