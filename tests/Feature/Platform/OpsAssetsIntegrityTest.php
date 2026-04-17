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
        $this->assertFileExists(base_path('ops/scripts/install-systemd-units.sh'));
        $this->assertFileExists(base_path('ops/scripts/bootstrap-shared-path.sh'));
        $this->assertFileExists(base_path('ops/scripts/prepare-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/promote-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/rollback-to-previous-release.sh'));
        $this->assertFileExists(base_path('ops/scripts/check-backup-readiness.sh'));
        $this->assertFileExists(base_path('ops/scripts/record-backup-success.sh'));
        $this->assertFileExists(base_path('ops/scripts/run-restore-drill.sh'));

        $envTemplate = file_get_contents(base_path('ops/systemd/velmix-app.env.example'));
        $this->assertIsString($envTemplate);
        $this->assertStringContainsString('VELMIX_CURRENT_LINK=/var/www/velmix/current', $envTemplate);
        $this->assertStringContainsString('VELMIX_PREVIOUS_LINK=/var/www/velmix/previous', $envTemplate);
        $this->assertStringContainsString('VELMIX_SYSTEMD_TARGET=velmix-backend.target', $envTemplate);
        $this->assertStringContainsString('VELMIX_BACKUP_ENABLED=true', $envTemplate);
        $this->assertStringContainsString('VELMIX_RESTORE_DRILL_PATH=/var/www/velmix/shared/restore-drills', $envTemplate);

        $schedulerUnit = file_get_contents(base_path('ops/systemd/velmix-scheduler.service'));
        $this->assertIsString($schedulerUnit);
        $this->assertStringContainsString('EnvironmentFile=-/etc/velmix/velmix.env', $schedulerUnit);
        $this->assertStringContainsString('ExecStartPre=/usr/bin/php artisan system:preflight --json --fail-on-critical', $schedulerUnit);
        $this->assertStringContainsString('WantedBy=velmix-backend.target', $schedulerUnit);

        $queueWorkerUnit = file_get_contents(base_path('ops/systemd/velmix-queue-worker.service'));
        $this->assertIsString($queueWorkerUnit);
        $this->assertStringContainsString('ExecStart=/usr/bin/php artisan queue:work', $queueWorkerUnit);
        $this->assertStringContainsString('ExecReload=/usr/bin/php artisan queue:restart', $queueWorkerUnit);
        $this->assertStringContainsString('WantedBy=velmix-backend.target', $queueWorkerUnit);

        $promoteScript = file_get_contents(base_path('ops/scripts/promote-release.sh'));
        $this->assertIsString($promoteScript);
        $this->assertStringContainsString('mv -Tf "${CURRENT_LINK}.next" "$CURRENT_LINK"', $promoteScript);
        $this->assertStringContainsString('artisan system:preflight --json --fail-on-warning', $promoteScript);
        $this->assertStringContainsString('systemctl restart "$SYSTEMD_TARGET"', $promoteScript);

        $rollbackScript = file_get_contents(base_path('ops/scripts/rollback-to-previous-release.sh'));
        $this->assertIsString($rollbackScript);
        $this->assertStringContainsString('artisan system:preflight --json --fail-on-critical', $rollbackScript);
        $this->assertStringContainsString('mv -Tf "${CURRENT_LINK}.rollback" "$CURRENT_LINK"', $rollbackScript);

        $installUnitsScript = file_get_contents(base_path('ops/scripts/install-systemd-units.sh'));
        $this->assertIsString($installUnitsScript);
        $this->assertStringContainsString('systemctl daemon-reload', $installUnitsScript);
        $this->assertStringContainsString('systemctl enable velmix-backend.target', $installUnitsScript);
        $this->assertStringContainsString('velmix-app.env.example', $installUnitsScript);

        $bootstrapScript = file_get_contents(base_path('ops/scripts/bootstrap-shared-path.sh'));
        $this->assertIsString($bootstrapScript);
        $this->assertStringContainsString('VELMIX_BACKUP_STORAGE_PATH', $bootstrapScript);
        $this->assertStringContainsString('VELMIX_RESTORE_DRILL_PATH', $bootstrapScript);

        $checkBackupScript = file_get_contents(base_path('ops/scripts/check-backup-readiness.sh'));
        $this->assertIsString($checkBackupScript);
        $this->assertStringContainsString('artisan system:backup-readiness --json --fail-on-warning', $checkBackupScript);

        $recordBackupScript = file_get_contents(base_path('ops/scripts/record-backup-success.sh'));
        $this->assertIsString($recordBackupScript);
        $this->assertStringContainsString('artisan system:record-backup', $recordBackupScript);

        $restoreDrillScript = file_get_contents(base_path('ops/scripts/run-restore-drill.sh'));
        $this->assertIsString($restoreDrillScript);
        $this->assertStringContainsString('artisan system:restore-drill --json --fail-on-warning', $restoreDrillScript);

        $healthScript = file_get_contents(base_path('ops/scripts/check-backend-health.sh'));
        $this->assertIsString($healthScript);
        $this->assertStringContainsString('artisan system:observability-report --json', $healthScript);
        $this->assertStringContainsString('artisan system:backup-readiness --json', $healthScript);
    }
}
