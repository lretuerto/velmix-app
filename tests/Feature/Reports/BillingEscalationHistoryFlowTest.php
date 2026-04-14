<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingEscalationHistoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_billing_escalation_history_index_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->recordEscalationWorkflow($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations/history?date=2026-03-12&days=1&history_days=30&limit=10')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.tracked_count', 6)
            ->assertJsonPath('data.summary.active_count', 6)
            ->assertJsonPath('data.summary.with_history_count', 1)
            ->assertJsonPath('data.summary.resolved_count', 1)
            ->assertJsonFragment([
                'code' => 'billing.failed_backlog',
                'workflow_status' => 'resolved',
                'is_currently_triggered' => true,
                'last_event_type' => 'billing.escalation.resolved',
                'timeline_count' => 2,
            ]);
    }

    public function test_reads_billing_escalation_detail_with_timeline_and_state(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->recordEscalationWorkflow($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations/billing.failed_backlog?date=2026-03-12&days=1&history_days=30&activity_limit=10')
            ->assertOk()
            ->assertJsonPath('data.code', 'billing.failed_backlog')
            ->assertJsonPath('data.workflow_status', 'resolved')
            ->assertJsonPath('data.is_currently_triggered', true)
            ->assertJsonPath('data.active_item.code', 'billing.failed_backlog')
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Retry manual ejecutado y monitoreo en curso.')
            ->assertJsonPath('data.latest_note', 'Retry manual ejecutado y monitoreo en curso.')
            ->assertJsonPath('data.timeline_summary.total_count', 2)
            ->assertJsonPath('data.timeline_summary.acknowledged_count', 1)
            ->assertJsonPath('data.timeline_summary.resolved_count', 1)
            ->assertJsonPath('data.timeline.0.event_type', 'billing.escalation.resolved')
            ->assertJsonPath('data.timeline.1.event_type', 'billing.escalation.acknowledged');
    }

    public function test_cashier_cannot_read_billing_escalation_history(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalations/history')
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_read_billing_escalation_history_detail(): void
    {
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');
        $this->seedEscalationScenario($admin10);
        $this->recordEscalationWorkflow($admin10);

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson('/reports/billing-escalations/billing.failed_backlog')
            ->assertStatus(404);
    }

    private function recordEscalationWorkflow(User $admin): void
    {
        Carbon::setTestNow('2026-03-12 08:30:00');

        try {
            $this->actingAs($admin)
                ->withHeader('X-Tenant-Id', '10')
                ->postJson('/reports/billing-escalations/billing.failed_backlog/acknowledge', [
                    'note' => 'Equipo de soporte revisando retry.',
                    'date' => '2026-03-12',
                    'days' => 1,
                ])
                ->assertOk();

            Carbon::setTestNow('2026-03-12 10:00:00');

            $this->actingAs($admin)
                ->withHeader('X-Tenant-Id', '10')
                ->postJson('/reports/billing-escalations/billing.failed_backlog/resolve', [
                    'note' => 'Retry manual ejecutado y monitoreo en curso.',
                    'date' => '2026-03-12',
                    'days' => 1,
                ])
                ->assertOk();
        } finally {
            Carbon::setTestNow();
        }
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
            reference: 'SALE-BILL-ESC-HISTORY-FAIL',
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
            reference: 'SALE-BILL-ESC-HISTORY-PENDING',
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
