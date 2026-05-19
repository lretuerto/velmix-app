<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReleasePromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_promotion_readiness_blocks_release_when_staging_certification_is_not_ready(): void
    {
        $root = storage_path('framework/testing/release-promotion-missing-certification');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');

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
            'velmix.staging_certification.storage_path' => $root.'/staging-certifications',
            'velmix.staging_certification.history_path' => $root.'/staging-certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_promotion.expected_environment' => 'staging',
            'velmix.release_promotion.required_environments' => ['staging'],
            'velmix.release_promotion.storage_path' => $root.'/release-promotions',
            'velmix.release_promotion.history_path' => $root.'/release-promotions/history',
            'velmix.release_promotion.release_identifier' => 'release-2026-04-21-001',
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

            $exitCode = Artisan::call('system:promotion-readiness', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertFalse($output['promotable']);
            $this->assertContains('release_promotion_staging_certification_not_ok', array_column($output['items'], 'code'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_record_release_promotion_persists_approval_when_all_gates_are_green(): void
    {
        $root = storage_path('framework/testing/release-promotion-record');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');

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
            'velmix.staging_certification.storage_path' => $root.'/staging-certifications',
            'velmix.staging_certification.history_path' => $root.'/staging-certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_promotion.expected_environment' => 'staging',
            'velmix.release_promotion.required_environments' => ['staging'],
            'velmix.release_promotion.storage_path' => $root.'/release-promotions',
            'velmix.release_promotion.history_path' => $root.'/release-promotions/history',
            'velmix.release_promotion.release_identifier' => 'release-2026-04-21-001',
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

            Artisan::call('system:record-staging-certification', [
                'release' => 'release-2026-04-21-001',
                'deploy_evidence' => 'https://staging.example.test/evidence/deploy-2026-04-21',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback-2026-04-21',
                '--smoke-evidence' => 'https://staging.example.test/evidence/smoke-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $recordExit = Artisan::call('system:record-release-promotion', [
                'release' => 'release-2026-04-21-001',
                'approval_evidence' => 'https://staging.example.test/evidence/approve-2026-04-21',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $recordOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $recordExit);
            $this->assertFileExists($recordOutput['manifest_path']);
            $this->assertFileExists($recordOutput['history_manifest_path']);

            $summaryExit = Artisan::call('system:promotion-readiness', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $summaryOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $summaryExit);
            $this->assertSame('ok', $summaryOutput['status']);
            $this->assertTrue($summaryOutput['promotable']);
            $this->assertTrue($summaryOutput['approval_recorded']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['latest_approval']['release']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['release_identifier']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
