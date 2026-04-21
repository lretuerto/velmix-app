<?php

namespace Tests\Feature\Platform;

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
            $this->assertSame(7, $output['scheduler']['alert_dispatch_every_minutes']);
            $this->assertSame(['log', 'webhook'], $output['notifications']['channels']);
            $this->assertSame('critical', $output['notifications']['minimum_severity']);
            $this->assertTrue($output['notifications']['webhook_enabled']);
            $this->assertSame('critical', $output['delivery']['minimum_severity']);
            $this->assertSame('ok', $output['recovery']['backup']['status']);
            $this->assertSame('ok', $output['recovery']['restore_drill']['status']);
            $this->assertSame('ok', $output['certification']['staging']['status']);
            $this->assertSame('release-2026-04-21-001', $output['certification']['staging']['latest_certification']['release']);
            $logChannel = collect($output['delivery']['channels'])->firstWhere('channel', 'log');
            $this->assertSame('ready', is_array($logChannel) ? ($logChannel['status'] ?? null) : null);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_system_observability_report_command_surfaces_critical_preflight_state(): void
    {
        config([
            'queue.default' => 'missing-queue',
        ]);

        $exitCode = Artisan::call('system:observability-report', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame('critical', $output['preflight']['status']);
    }
}
