<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingEscalationMetricsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_billing_escalation_metrics_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->seedEscalationWorkflowMetrics($admin);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalation-metrics?date=2026-03-12&days=1&history_days=30');

        $response->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.current_backlog.active_count', 6)
            ->assertJsonPath('data.current_backlog.open_count', 4)
            ->assertJsonPath('data.current_backlog.acknowledged_count', 1)
            ->assertJsonPath('data.current_backlog.resolved_but_active_count', 1)
            ->assertJsonPath('data.current_backlog.critical_count', 4)
            ->assertJsonPath('data.current_backlog.info_count', 2)
            ->assertJsonPath('data.current_backlog.stale_acknowledged_count', 1)
            ->assertJsonPath('data.workflow_events.acknowledged_event_count', 2)
            ->assertJsonPath('data.workflow_events.resolved_event_count', 1)
            ->assertJsonPath('data.resolution_sla.resolved_count', 1)
            ->assertJsonPath('data.resolution_sla.avg_minutes_from_ack_to_resolve', 90)
            ->assertJsonPath('data.resolution_sla.max_minutes_from_ack_to_resolve', 90)
            ->assertJsonPath('data.resolution_sla.within_240_minutes_rate', 100)
            ->assertJsonPath('data.recent_resolutions.0.code', 'billing.failed_backlog')
            ->assertJsonPath('data.recent_resolutions.0.minutes_from_ack_to_resolve', 90)
            ->assertJsonFragment([
                'code' => 'billing.pending_backlog',
                'workflow_status' => 'acknowledged',
            ]);

        $this->assertNotNull($response->json('data.current_backlog.oldest_acknowledged_age_minutes'));
    }

    public function test_cashier_cannot_read_billing_escalation_metrics(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-escalation-metrics')
            ->assertStatus(403);
    }

    private function seedEscalationWorkflowMetrics(User $admin): void
    {
        $resolvedStateId = DB::table('billing_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'billing.failed_backlog',
            'status' => 'resolved',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-12 08:30:00',
            'acknowledgement_note' => 'Equipo de soporte revisando retry.',
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => '2026-03-12 10:00:00',
            'resolution_note' => 'Retry manual ejecutado y monitoreo en curso.',
            'last_seen_at' => '2026-03-12 10:00:00',
            'created_at' => '2026-03-12 08:30:00',
            'updated_at' => '2026-03-12 10:00:00',
        ]);

        $staleAcknowledgedAt = '2026-03-11 11:00:00';
        $acknowledgedStateId = DB::table('billing_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'billing.pending_backlog',
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => $staleAcknowledgedAt,
            'acknowledgement_note' => 'Backlog en observacion.',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
            'last_seen_at' => $staleAcknowledgedAt,
            'created_at' => $staleAcknowledgedAt,
            'updated_at' => $staleAcknowledgedAt,
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'billing',
                'event_type' => 'billing.escalation.acknowledged',
                'aggregate_type' => 'billing_escalation_state',
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Billing escalation billing.failed_backlog acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'billing.failed_backlog',
                    'status' => 'acknowledged',
                    'note' => 'Equipo de soporte revisando retry.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 08:30:00',
                'created_at' => '2026-03-12 08:30:00',
                'updated_at' => '2026-03-12 08:30:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'billing',
                'event_type' => 'billing.escalation.resolved',
                'aggregate_type' => 'billing_escalation_state',
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Billing escalation billing.failed_backlog resolved.',
                'metadata' => json_encode([
                    'escalation_code' => 'billing.failed_backlog',
                    'status' => 'resolved',
                    'note' => 'Retry manual ejecutado y monitoreo en curso.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:00:00',
                'created_at' => '2026-03-12 10:00:00',
                'updated_at' => '2026-03-12 10:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'billing',
                'event_type' => 'billing.escalation.acknowledged',
                'aggregate_type' => 'billing_escalation_state',
                'aggregate_id' => $acknowledgedStateId,
                'summary' => 'Billing escalation billing.pending_backlog acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'billing.pending_backlog',
                    'status' => 'acknowledged',
                    'note' => 'Backlog en observacion.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $staleAcknowledgedAt,
                'created_at' => $staleAcknowledgedAt,
                'updated_at' => $staleAcknowledgedAt,
            ],
        ]);
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
            'number' => $this->nextVoucherNumber($tenantId, 'B001'),
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
            reference: 'SALE-BILL-ESC-METRICS-FAIL',
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
            reference: 'SALE-BILL-ESC-METRICS-PENDING',
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
