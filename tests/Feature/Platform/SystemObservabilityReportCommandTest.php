<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SystemObservabilityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_observability_report_command_emits_runtime_snapshot(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'queue.default' => 'database',
            'velmix.scheduler.alert_dispatch_every_minutes' => 7,
            'velmix.alerts.notifications.channels' => ['log', 'webhook'],
            'velmix.alerts.notifications.minimum_severity' => 'critical',
            'velmix.alerts.notifications.cooldown_minutes' => 45,
            'velmix.alerts.notifications.webhook_url' => 'https://alerts.example.test/velmix',
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
        $logChannel = collect($output['delivery']['channels'])->firstWhere('channel', 'log');
        $this->assertSame('ready', is_array($logChannel) ? ($logChannel['status'] ?? null) : null);
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
