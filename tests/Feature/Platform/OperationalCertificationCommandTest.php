<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OperationalCertificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_operational_certification_blocks_release_when_cutover_is_not_recorded(): void
    {
        $root = storage_path('framework/testing/operational-certification-missing-cutover');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');
        File::ensureDirectoryExists($root.'/release-cutovers/history');
        File::ensureDirectoryExists($root.'/operational-certifications/history');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'queue.default' => 'database',
            'velmix.cash_ledger_audit.enabled' => true,
            'velmix.cash_ledger_audit.required_environments' => ['production'],
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => 'test-passphrase',
            'velmix.staging_certification.expected_environment' => 'production',
            'velmix.staging_certification.required_environments' => ['production'],
            'velmix.staging_certification.storage_path' => $root.'/staging-certifications',
            'velmix.staging_certification.history_path' => $root.'/staging-certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_promotion.expected_environment' => 'production',
            'velmix.release_promotion.required_environments' => ['production'],
            'velmix.release_promotion.storage_path' => $root.'/release-promotions',
            'velmix.release_promotion.history_path' => $root.'/release-promotions/history',
            'velmix.release_promotion.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_cutover.expected_environment' => 'production',
            'velmix.release_cutover.required_environments' => ['production'],
            'velmix.release_cutover.storage_path' => $root.'/release-cutovers',
            'velmix.release_cutover.history_path' => $root.'/release-cutovers/history',
            'velmix.release_cutover.release_identifier' => 'release-2026-04-21-001',
            'velmix.operational_certification.expected_environment' => 'production',
            'velmix.operational_certification.required_environments' => ['production'],
            'velmix.operational_certification.storage_path' => $root.'/operational-certifications',
            'velmix.operational_certification.history_path' => $root.'/operational-certifications/history',
            'velmix.operational_certification.release_identifier' => 'release-2026-04-21-001',
        ]);

        try {
            Artisan::call('system:record-backup', [
                'artifact' => 's3://velmix-prod/backups/2026-04-21.sql.gz',
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
                'deploy_evidence' => 'https://prod.example.test/evidence/deploy-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                '--smoke-evidence' => 'https://prod.example.test/evidence/smoke-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-release-promotion', [
                'release' => 'release-2026-04-21-001',
                'approval_evidence' => 'https://prod.example.test/evidence/approve-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $exitCode = Artisan::call('system:operational-certification', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertFalse($output['operationally_certified']);
            $this->assertContains('operational_certification_cutover_not_recorded', array_column($output['items'], 'code'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_record_operational_certification_persists_evidence_when_all_gates_are_green(): void
    {
        $root = storage_path('framework/testing/operational-certification-record');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');
        File::ensureDirectoryExists($root.'/release-cutovers/history');
        File::ensureDirectoryExists($root.'/operational-certifications/history');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'queue.default' => 'database',
            'velmix.cash_ledger_audit.enabled' => true,
            'velmix.cash_ledger_audit.required_environments' => ['production'],
            'velmix.backup.enabled' => true,
            'velmix.backup.storage_path' => $root.'/backups',
            'velmix.backup.history_path' => $root.'/backups/history',
            'velmix.backup.restore_drill_path' => $root.'/restore-drills',
            'velmix.backup.encryption_passphrase' => 'test-passphrase',
            'velmix.staging_certification.expected_environment' => 'production',
            'velmix.staging_certification.required_environments' => ['production'],
            'velmix.staging_certification.storage_path' => $root.'/staging-certifications',
            'velmix.staging_certification.history_path' => $root.'/staging-certifications/history',
            'velmix.staging_certification.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_promotion.expected_environment' => 'production',
            'velmix.release_promotion.required_environments' => ['production'],
            'velmix.release_promotion.storage_path' => $root.'/release-promotions',
            'velmix.release_promotion.history_path' => $root.'/release-promotions/history',
            'velmix.release_promotion.release_identifier' => 'release-2026-04-21-001',
            'velmix.release_cutover.expected_environment' => 'production',
            'velmix.release_cutover.required_environments' => ['production'],
            'velmix.release_cutover.storage_path' => $root.'/release-cutovers',
            'velmix.release_cutover.history_path' => $root.'/release-cutovers/history',
            'velmix.release_cutover.release_identifier' => 'release-2026-04-21-001',
            'velmix.operational_certification.expected_environment' => 'production',
            'velmix.operational_certification.required_environments' => ['production'],
            'velmix.operational_certification.storage_path' => $root.'/operational-certifications',
            'velmix.operational_certification.history_path' => $root.'/operational-certifications/history',
            'velmix.operational_certification.release_identifier' => 'release-2026-04-21-001',
        ]);

        try {
            Artisan::call('system:record-backup', [
                'artifact' => 's3://velmix-prod/backups/2026-04-21.sql.gz',
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
                'deploy_evidence' => 'https://prod.example.test/evidence/deploy-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                '--smoke-evidence' => 'https://prod.example.test/evidence/smoke-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-release-promotion', [
                'release' => 'release-2026-04-21-001',
                'approval_evidence' => 'https://prod.example.test/evidence/approve-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-release-cutover', [
                'release' => 'release-2026-04-21-001',
                'cutover_evidence' => 'https://prod.example.test/evidence/cutover-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                '--monitoring-evidence' => 'https://prod.example.test/evidence/monitoring-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $recordExit = Artisan::call('system:record-operational-certification', [
                'release' => 'release-2026-04-21-001',
                'deploy_evidence' => 'https://prod.example.test/evidence/deploy-2026-04-21',
                'rollback_evidence' => 'https://prod.example.test/evidence/rollback-2026-04-21',
                'backup_artifact' => 's3://velmix-prod/backups/2026-04-21.sql.gz',
                'restore_evidence' => 'https://prod.example.test/evidence/restore-2026-04-21',
                '--monitoring-evidence' => 'https://prod.example.test/evidence/monitoring-2026-04-21',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $recordOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $recordExit);
            $this->assertFileExists($recordOutput['manifest_path']);
            $this->assertFileExists($recordOutput['history_manifest_path']);

            $summaryExit = Artisan::call('system:operational-certification', [
                '--json' => true,
                '--fail-on-warning' => true,
            ]);

            $summaryOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $summaryExit);
            $this->assertSame('ok', $summaryOutput['status']);
            $this->assertTrue($summaryOutput['operationally_certified']);
            $this->assertTrue($summaryOutput['certificate_recorded']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['latest_certificate']['release']);
            $this->assertSame('release-2026-04-21-001', $summaryOutput['release_identifier']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
