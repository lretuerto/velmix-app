<?php

namespace Tests\Feature\Platform;

use App\Services\Frontend\FrontendUatArtifactPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemObservabilityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_observability_report_command_emits_runtime_snapshot(): void
    {
        $root = storage_path('framework/testing/observability-recovery');
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/backups/history');
        File::ensureDirectoryExists($root.'/restore-drills');
        File::ensureDirectoryExists($root.'/staging-certifications/history');
        File::ensureDirectoryExists($root.'/release-promotions/history');
        File::ensureDirectoryExists($root.'/release-cutovers/history');
        File::ensureDirectoryExists($root.'/operational-certifications/history');

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'queue.default' => 'database',
            'velmix.scheduler.alert_dispatch_every_minutes' => 7,
            'velmix.alerts.notifications.channels' => ['log', 'webhook'],
            'velmix.alerts.notifications.minimum_severity' => 'critical',
            'velmix.alerts.notifications.cooldown_minutes' => 45,
            'velmix.alerts.notifications.webhook_url' => 'https://alerts.example.test/velmix',
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
            'velmix.release_cutover.expected_environment' => 'staging',
            'velmix.release_cutover.required_environments' => ['staging'],
            'velmix.release_cutover.storage_path' => $root.'/release-cutovers',
            'velmix.release_cutover.history_path' => $root.'/release-cutovers/history',
            'velmix.release_cutover.release_identifier' => 'release-2026-04-21-001',
            'velmix.operational_certification.expected_environment' => 'staging',
            'velmix.operational_certification.required_environments' => ['staging'],
            'velmix.operational_certification.storage_path' => $root.'/operational-certifications',
            'velmix.operational_certification.history_path' => $root.'/operational-certifications/history',
            'velmix.operational_certification.release_identifier' => 'release-2026-04-21-001',
        ]);

        try {
            Artisan::call('system:record-backup', [
                'artifact' => 's3://velmix-prod/backups/latest.sql.gz',
                '--checksum' => 'sha256:test',
                '--size' => 2048,
                '--generated-at' => now()->subHour()->toIso8601String(),
                '--driver' => 'managed-snapshot',
                '--json' => true,
            ]);

            Artisan::call('system:restore-drill', [
                '--json' => true,
            ]);

            Artisan::call('system:record-staging-certification', [
                'release' => 'release-2026-04-21-001',
                'deploy_evidence' => 'https://staging.example.test/evidence/deploy',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback',
                '--smoke-evidence' => 'https://staging.example.test/evidence/smoke',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-release-promotion', [
                'release' => 'release-2026-04-21-001',
                'approval_evidence' => 'https://staging.example.test/evidence/approve',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-release-cutover', [
                'release' => 'release-2026-04-21-001',
                'cutover_evidence' => 'https://staging.example.test/evidence/cutover',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback',
                '--monitoring-evidence' => 'https://staging.example.test/evidence/monitoring',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            Artisan::call('system:record-operational-certification', [
                'release' => 'release-2026-04-21-001',
                'deploy_evidence' => 'https://staging.example.test/evidence/deploy',
                'rollback_evidence' => 'https://staging.example.test/evidence/rollback',
                'backup_artifact' => 's3://velmix-prod/backups/latest.sql.gz',
                'restore_evidence' => 'https://staging.example.test/evidence/restore',
                '--monitoring-evidence' => 'https://staging.example.test/evidence/monitoring',
                '--operator' => 'release-bot',
                '--json' => true,
            ]);

            $exitCode = Artisan::call('system:observability-report', [
                '--json' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exitCode);
            $this->assertContains($output['status'], ['ok', 'warning']);
            $this->assertSame('X-Request-Id', $output['request_correlation']['request_id_header']);
            $this->assertTrue($output['logging']['structured_logging_enabled']);
            $this->assertContains('stderr_json', $output['logging']['effective_channels']);
            $this->assertSame('database', $output['queue']['connection']);
            $this->assertContains($output['preflight']['status'], ['ok', 'warning']);
            $this->assertSame('ok', $output['cash_ledger']['status']);
            $this->assertFalse($output['cash_ledger']['enabled']);
            $this->assertFalse($output['cash_ledger']['required']);
            $this->assertSame(0, $output['cash_ledger']['issue_count']);
            $this->assertSame('ok', $output['frontend_uat_release_gate']['status']);
            $this->assertFalse($output['frontend_uat_release_gate']['enabled']);
            $this->assertFalse($output['frontend_uat_release_gate']['required']);
            $this->assertSame(7, $output['scheduler']['alert_dispatch_every_minutes']);
            $this->assertSame(['log', 'webhook'], $output['notifications']['channels']);
            $this->assertSame('critical', $output['notifications']['minimum_severity']);
            $this->assertTrue($output['notifications']['webhook_enabled']);
            $this->assertSame('critical', $output['delivery']['minimum_severity']);
            $this->assertSame('ok', $output['recovery']['backup']['status']);
            $this->assertSame('ok', $output['recovery']['restore_drill']['status']);
            $this->assertSame('ok', $output['certification']['staging']['status']);
            $this->assertSame('release-2026-04-21-001', $output['certification']['staging']['latest_certification']['release']);
            $this->assertSame('ok', $output['promotion']['status']);
            $this->assertTrue($output['promotion']['promotable']);
            $this->assertTrue($output['promotion']['approval_recorded']);
            $this->assertSame('release-2026-04-21-001', $output['promotion']['latest_approval']['release']);
            $this->assertSame('ok', $output['cutover']['status']);
            $this->assertTrue($output['cutover']['ready_for_cutover']);
            $this->assertTrue($output['cutover']['decision_recorded']);
            $this->assertSame('release-2026-04-21-001', $output['cutover']['latest_decision']['release']);
            $this->assertSame('ok', $output['operational_certification']['status']);
            $this->assertTrue($output['operational_certification']['operationally_certified']);
            $this->assertTrue($output['operational_certification']['certificate_recorded']);
            $this->assertSame('release-2026-04-21-001', $output['operational_certification']['latest_certificate']['release']);
            $logChannel = collect($output['delivery']['channels'])->firstWhere('channel', 'log');
            $this->assertSame('ready', is_array($logChannel) ? ($logChannel['status'] ?? null) : null);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_system_observability_report_command_surfaces_critical_preflight_state(): void
    {
        File::deleteDirectory(FrontendUatArtifactPaths::baseDirectory());

        config([
            'queue.default' => 'missing-queue',
            'velmix.frontend_uat_release_gate.enabled' => true,
            'velmix.frontend_uat_release_gate.required_environments' => ['testing'],
        ]);

        $exitCode = Artisan::call('system:observability-report', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame('critical', $output['preflight']['status']);
        $this->assertSame('critical', $output['frontend_uat_release_gate']['status']);
        $this->assertSame('blocked', $output['frontend_uat_release_gate']['readiness_status']);
    }
}
