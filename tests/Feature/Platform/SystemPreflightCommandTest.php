<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_preflight_command_reports_ok_under_default_local_configuration(): void
    {
        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertSame('ready', $output['checks']['readiness']['status']);
        $this->assertSame('ok', $output['checks']['platform_safety']['status']);
        $this->assertSame([], $output['items']);
    }

    public function test_system_preflight_command_fails_on_warning_for_unsafe_scheduler_lock_store(): void
    {
        config([
            'velmix.scheduler.on_one_server' => true,
            'cache.default' => 'file',
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertContains('scheduler_lock_store_not_shared', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_fails_on_critical_for_missing_queue_connection(): void
    {
        config([
            'queue.default' => 'missing-queue',
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertContains('queue_connection_missing', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_warns_when_structured_logging_is_not_enabled_in_production_like_env(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
        ]);

        $exitCode = Artisan::call('system:preflight', [
            '--json' => true,
            '--fail-on-warning' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $output['status']);
        $this->assertContains('structured_logging_not_enabled', array_column($output['items'], 'code'));
    }

    public function test_system_preflight_command_fails_when_required_writable_path_is_missing(): void
    {
        $storagePath = storage_path();
        $tempStoragePath = storage_path('framework/testing/preflight-missing-logs');

        File::deleteDirectory($tempStoragePath);
        File::ensureDirectoryExists($tempStoragePath);
        $this->app->useStoragePath($tempStoragePath);

        try {
            $exitCode = Artisan::call('system:preflight', [
                '--json' => true,
                '--fail-on-critical' => true,
            ]);

            $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('critical', $output['status']);
            $this->assertContains('writable_path_missing', array_column($output['items'], 'code'));
        } finally {
            $this->app->useStoragePath($storagePath);
            File::deleteDirectory($tempStoragePath);
        }
    }
}
