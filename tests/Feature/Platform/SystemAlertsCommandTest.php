<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemAlertsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_alerts_command_reports_ok_when_no_operational_alerts_exist(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $exitCode = Artisan::call('system:alerts', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('ok', $output['status']);
        $this->assertSame(0, $output['summary']['critical_count']);
    }

    public function test_system_alerts_command_fails_on_critical_when_failed_billing_backlog_exists(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

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

        $exitCode = Artisan::call('system:alerts', [
            '--json' => true,
            '--fail-on-critical' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertContains('billing_critical', array_column($output['items'], 'code'));
    }
}
