<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StagingCertificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_staging_certification_warns_when_manifest_is_missing_for_required_environment(): void
    {
        $root = storage_path('framework/testing/staging-certification-missing');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/certifications/history');

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'velmix.staging_certification.expected_environment' => 'staging',
            'velmix.staging_certification.required_environments' => ['staging'],
            'velmix.staging_certification.storage_path' => $root.'/certifications',
            'velmix.staging_certification.history_path' => $root.'/certifications/history',
        ]);

        try {
            $exitCode = Artisan::call('system:staging-certification', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('warning', $output['status']);
            $this->assertContains('staging_certification_missing', array_column($output['items'], 'code'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_record_staging_certification_persists_release_evidence_when_platform_gates_are_green(): void
    {
        $root = storage_path('framework/testing/staging-certification-record');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/certifications/history');

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'queue.default' => 'database',
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => 'test-passphrase',
            'velmix.staging_certification.expected_environment' => 'staging',
            'velmix.staging_certification.required_environments' => ['staging'],
            'velmix.staging_certification.storage_path' => $root.'/certifications',
            'velmix.staging_certification.history_path' => $root.'/certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
        ]);

        try {
            Artisan::call('system:record-backup', [
                'artifact' => 's3://velmix-staging/backups/2026-04-21.sql.gz',
                '--checksum' => 'sha256:test',
                '--size' => 2048,
                '--driver' => 'managed-snapshot',
                '--generated-at' => now()->subHour()->toIso8601String(),
                '--json' => true,
            ]);

            Artisan::call('system:restore-drill', [
                '--json' => true,
            ]);

            $recordExit = Artisan::call('system:record-staging-certification', [
                'release' => 'release-2026-04-21-001',
                'deploy_evidence' => 'https://staging.example.test/evidence/deploy-2026-04-21',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback-2026-04-21',
                '--smoke-evidence' => 'https://staging.example.test/evidence/smoke-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $recordOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $recordExit);
            $this->assertFileExists($recordOutput['manifest_path']);
            $this->assertFileExists($recordOutput['history_manifest_path']);

            $summaryExit = Artisan::call('system:staging-certification', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $summaryOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $summaryExit);
            $this->assertSame('ok', $summaryOutput['status']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['latest_certification']['release']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['release_identifier']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
