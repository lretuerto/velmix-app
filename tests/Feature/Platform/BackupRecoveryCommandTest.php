<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_backup_readiness_warns_when_backup_is_disabled_in_production_like_env(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'velmix.backup.enabled' => false,
        ]);

        $exitCode = Artisan::call('system:backup-readiness', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertContains('backup_disabled', array_column($output['items'], 'code'));
    }

    public function test_system_backup_readiness_fails_when_encryption_passphrase_is_missing(): void
    {
        $root = storage_path('framework/testing/backup-recovery-critical');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => null,
        ]);

        try {
            $exitCode = Artisan::call('system:backup-readiness', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertContains('backup_encryption_passphrase_missing', array_column($output['items'], 'code'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_record_backup_and_restore_drill_commands_persist_recovery_artifacts(): void
    {
        $root = storage_path('framework/testing/backup-recovery');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => 'test-passphrase',
        ]);

        try {
            $recordExit = Artisan::call('system:record-backup', [
                'artifact' => 's3://velmix-prod/backups/2026-04-17.sql.gz',
                '--checksum' => 'sha256:abc123',
                '--size' => 1024,
                '--driver' => 'managed-snapshot',
                '--generated-at' => now()->subHour()->toIso8601String(),
                '--json' => true,
            ]);

            $recordOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $recordExit);
            $this->assertFileExists($recordOutput['manifest_path']);
            $this->assertFileExists($recordOutput['history_manifest_path']);

            $readinessExit = Artisan::call('system:backup-readiness', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $readinessOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $readinessExit);
            $this->assertSame('ok', $readinessOutput['status']);
            $this->assertSame('managed-snapshot', $readinessOutput['latest_backup']['driver']);

            $drillExit = Artisan::call('system:restore-drill', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $drillOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $drillExit);
            $this->assertSame('ok', $drillOutput['status']);
            $this->assertFileExists($drillOutput['report_path']);
            $this->assertSame('ok', $drillOutput['latest_drill']['status']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
