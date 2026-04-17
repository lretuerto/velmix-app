<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformObservabilityReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_platform_observability_report(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');

        config([
            'app.env' => 'production',
            'app.debug' => false,
            'queue.default' => 'database',
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single', 'stderr_json'],
            'velmix.alerts.notifications.channels' => ['log', 'slack'],
            'velmix.alerts.notifications.minimum_severity' => 'warning',
            'velmix.alerts.notifications.slack_webhook_url' => 'https://hooks.slack.example.test/services/velmix',
            'velmix.alerts.notifications.slack_channel' => '#ops-alerts',
        ]);

        DB::table('outbox_events')->insert([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => 900,
            'event_type' => 'voucher_issued',
            'payload' => json_encode(['document_number' => 'B001-000900'], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/platform-observability?date=2026-04-17');

        $response->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.request_correlation.request_id_header', 'X-Request-Id')
            ->assertJsonPath('data.notifications.slack_enabled', true)
            ->assertJsonPath('data.delivery.minimum_severity', 'warning');

        $data = $response->json('data');
        $channels = collect($data['delivery']['channels']);
        $logChannel = $channels->firstWhere('channel', 'log');
        $slackChannel = $channels->firstWhere('channel', 'slack');

        $this->assertSame('critical', $data['alerts']['status']);
        $this->assertGreaterThanOrEqual(1, $data['delivery']['candidate_alert_count']);
        $this->assertSame('ready', is_array($logChannel) ? ($logChannel['status'] ?? null) : null);
        $this->assertSame('ready', is_array($slackChannel) ? ($slackChannel['status'] ?? null) : null);
        $this->assertIsArray($data['recommendations']);
    }

    public function test_cashier_cannot_read_platform_observability_report(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/platform-observability')
            ->assertStatus(403);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
