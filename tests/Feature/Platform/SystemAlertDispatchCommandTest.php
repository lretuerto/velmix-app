<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SystemAlertDispatchCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_system_alert_dispatch_command_logs_critical_billing_alerts(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $logPath = storage_path('framework/testing/system-alert-dispatch.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::delete($logPath);

        config([
            'velmix.alerts.notifications.channels' => ['log'],
            'velmix.alerts.notifications.log_channel' => 'alert_test',
            'logging.channels.alert_test' => [
                'driver' => 'single',
                'path' => $logPath,
                'level' => 'warning',
                'replace_placeholders' => true,
            ],
        ]);

        $this->insertFailedOutboxEvent();

        $exitCode = Artisan::call('system:dispatch-alerts', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertGreaterThanOrEqual(1, $output['notification']['candidate_alert_count']);
        $this->assertSame(
            $output['notification']['candidate_alert_count'],
            $output['notification']['dispatched_count']
        );
        $this->assertSame(0, $output['notification']['failed_count']);
        $this->assertSame(0, $output['notification']['suppressed_count']);
        $this->assertFileExists($logPath);

        $logContent = File::get($logPath);

        $this->assertStringContainsString('system_alert_notification', $logContent);
        $this->assertStringContainsString('billing_critical', $logContent);
    }

    public function test_system_alert_dispatch_command_suppresses_duplicates_during_cooldown(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        config([
            'cache.default' => 'array',
            'velmix.alerts.notifications.channels' => ['log'],
            'velmix.alerts.notifications.cooldown_minutes' => 30,
            'velmix.alerts.notifications.log_channel' => 'alert_test',
            'logging.channels.alert_test' => [
                'driver' => 'single',
                'path' => storage_path('framework/testing/system-alert-dispatch-cooldown.log'),
                'level' => 'warning',
                'replace_placeholders' => true,
            ],
        ]);

        $this->insertFailedOutboxEvent();

        Artisan::call('system:dispatch-alerts', [
            '--json' => true,
        ]);

        $secondExitCode = Artisan::call('system:dispatch-alerts', [
            '--json' => true,
        ]);

        $secondOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExitCode);
        $this->assertSame('ok', $secondOutput['status']);
        $this->assertSame(0, $secondOutput['notification']['dispatched_count']);
        $this->assertSame(
            $secondOutput['notification']['candidate_alert_count'],
            $secondOutput['notification']['suppressed_count']
        );
    }

    public function test_system_alert_dispatch_command_sends_webhook_notifications(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        Http::fake([
            'https://alerts.example.test/velmix' => Http::response(['ok' => true], 202),
        ]);

        config([
            'velmix.alerts.notifications.channels' => ['webhook'],
            'velmix.alerts.notifications.webhook_url' => 'https://alerts.example.test/velmix',
            'velmix.alerts.notifications.minimum_severity' => 'warning',
        ]);

        $this->insertFailedOutboxEvent();

        $exitCode = Artisan::call('system:dispatch-alerts', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertGreaterThanOrEqual(1, $output['notification']['dispatched_count']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://alerts.example.test/velmix'
                && ($request['alert']['code'] ?? null) === 'billing_critical'
                && ($request['alert']['tenant_id'] ?? null) === 10;
        });
    }

    public function test_system_alert_dispatch_command_can_fail_on_delivery_errors(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        config([
            'velmix.alerts.notifications.channels' => ['webhook'],
            'velmix.alerts.notifications.webhook_url' => null,
        ]);

        $this->insertFailedOutboxEvent();

        $exitCode = Artisan::call('system:dispatch-alerts', [
            '--json' => true,
            '--fail-on-error' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('partial_failure', $output['status']);
        $this->assertGreaterThanOrEqual(1, $output['notification']['failed_count']);
        $this->assertSame(
            $output['notification']['candidate_alert_count'],
            $output['notification']['failed_count']
        );
    }

    private function insertFailedOutboxEvent(): void
    {
        DB::table('outbox_events')->insert([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => 501,
            'event_type' => 'voucher_issued',
            'payload' => json_encode(['document_number' => 'B001-000501'], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
