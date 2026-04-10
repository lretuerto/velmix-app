<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingEscalationReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_billing_escalations_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations?date=2026-03-12&days=1&limit=10')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.open_count', 6)
            ->assertJsonPath('data.summary.critical_count', 4)
            ->assertJsonPath('data.summary.warning_count', 0)
            ->assertJsonPath('data.summary.info_count', 2)
            ->assertJsonPath('data.summary.workflow.open_count', 6)
            ->assertJsonPath('data.summary.workflow.acknowledged_count', 0)
            ->assertJsonPath('data.summary.workflow.resolved_count', 0)
            ->assertJsonPath('data.items.0.code', 'billing.health_stale')
            ->assertJsonPath('data.items.0.workflow_status', 'open')
            ->assertJsonPath('data.items.1.code', 'billing.failed_backlog')
            ->assertJsonFragment(['code' => 'billing.pending_backlog'])
            ->assertJsonFragment(['code' => 'billing.failure_rate_high'])
            ->assertJsonFragment(['code' => 'billing.replay_backlog'])
            ->assertJsonFragment(['code' => 'billing.mixed_environments']);
    }

    public function test_admin_can_acknowledge_and_resolve_billing_escalation(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/billing-escalations/billing.failed_backlog/acknowledge', [
                'note' => 'Equipo de soporte revisando retry.',
                'date' => '2026-03-12',
                'days' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.state.code', 'billing.failed_backlog')
            ->assertJsonPath('data.state.status', 'acknowledged')
            ->assertJsonPath('data.state.acknowledgement_note', 'Equipo de soporte revisando retry.')
            ->assertJsonPath('data.active_item.workflow_status', 'acknowledged');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations?date=2026-03-12&days=1&limit=10')
            ->assertOk()
            ->assertJsonPath('data.summary.workflow.acknowledged_count', 1)
            ->assertJsonPath('data.summary.workflow.open_count', 5)
            ->assertJsonFragment([
                'code' => 'billing.failed_backlog',
                'workflow_status' => 'acknowledged',
            ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/billing-escalations/billing.failed_backlog/resolve', [
                'note' => 'Retry manual ejecutado y monitoreo en curso.',
                'date' => '2026-03-12',
                'days' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Retry manual ejecutado y monitoreo en curso.')
            ->assertJsonPath('data.active_item.workflow_status', 'resolved');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations?date=2026-03-12&days=1&limit=10')
            ->assertOk()
            ->assertJsonPath('data.summary.workflow.resolved_count', 1)
            ->assertJsonFragment([
                'code' => 'billing.failed_backlog',
                'workflow_status' => 'resolved',
            ]);
    }

    public function test_cashier_cannot_read_billing_escalations(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations')
            ->assertStatus(403);
    }

    public function test_cashier_cannot_manage_billing_escalations(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/billing-escalations/billing.failed_backlog/acknowledge', [
                'note' => 'Intento sin permiso.',
            ])
            ->assertStatus(403);
    }

    public function test_resolve_requires_note(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/billing-escalations/billing.failed_backlog/resolve', [])
            ->assertStatus(422);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

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

    private function seedVoucherEvent(
        int $tenantId,
        int $userId,
        string $reference,
        string $voucherStatus,
        string $eventStatus,
        string $createdAt,
        ?string $attemptStatus,
        ?string $attemptCreatedAt,
        string $providerEnvironment,
        ?int $replayedFromEventId = null,
    ): array {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 25.00,
            'gross_cost' => 10.00,
            'gross_margin' => 15.00,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => random_int(1, 9999),
            'status' => $voucherStatus,
            'sunat_ticket' => $attemptStatus === 'accepted' ? 'SUNAT-'.$reference : null,
            'rejection_reason' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
            'created_at' => $createdAt,
            'updated_at' => $attemptCreatedAt ?? $createdAt,
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $voucherId,
                'provider_code' => 'fake_sunat',
                'provider_environment' => $providerEnvironment,
            ], JSON_THROW_ON_ERROR),
            'status' => $eventStatus,
            'retry_count' => $attemptStatus === 'failed' ? 1 : 0,
            'last_error' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
            'replayed_from_event_id' => $replayedFromEventId,
            'created_at' => $createdAt,
            'updated_at' => $attemptCreatedAt ?? $createdAt,
        ]);

        if ($attemptStatus !== null && $attemptCreatedAt !== null) {
            DB::table('outbox_attempts')->insert([
                'outbox_event_id' => $eventId,
                'status' => $attemptStatus,
                'provider_code' => 'fake_sunat',
                'provider_environment' => $providerEnvironment,
                'provider_reference' => 'SUNAT-'.$reference,
                'sunat_ticket' => $attemptStatus === 'accepted' ? 'SUNAT-'.$reference : null,
                'error_message' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
                'created_at' => $attemptCreatedAt,
                'updated_at' => $attemptCreatedAt,
            ]);
        }

        return [
            'sale_id' => $saleId,
            'voucher_id' => $voucherId,
            'event_id' => $eventId,
        ];
    }

    private function seedEscalationScenario(User $admin): void
    {
        DB::table('billing_provider_profiles')->insert([
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'environment' => 'live',
            'default_outcome' => 'accepted',
            'credentials' => null,
            'health_status' => 'unknown',
            'health_checked_at' => now()->subHours(80),
            'health_message' => 'Health check is stale.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failed = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-ESC-FAIL',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-12 08:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-12 08:20:00',
            providerEnvironment: 'sandbox',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-ESC-PENDING',
            voucherStatus: 'pending',
            eventStatus: 'pending',
            createdAt: '2026-03-12 09:00:00',
            attemptStatus: null,
            attemptCreatedAt: null,
            providerEnvironment: 'live',
            replayedFromEventId: $failed['event_id'],
        );
    }
}
