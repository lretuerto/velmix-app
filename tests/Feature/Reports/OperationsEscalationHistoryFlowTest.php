<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsEscalationHistoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_unified_operations_escalation_history_index_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedBillingScenario($admin);
        $this->seedFinanceScenario($admin);
        $this->seedUnifiedHistory($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-escalations/history?date=2026-03-12&billing_days=3&finance_days_ahead=7&history_days=30&limit=20&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.tracked_count', 10)
            ->assertJsonPath('data.summary.active_count', 10)
            ->assertJsonPath('data.summary.with_history_count', 2)
            ->assertJsonPath('data.summary.workflow.resolved_count', 2)
            ->assertJsonPath('data.summary.by_domain.billing_count', 6)
            ->assertJsonPath('data.summary.by_domain.finance_count', 4)
            ->assertJsonFragment([
                'domain' => 'billing',
                'code' => 'billing.failed_backlog',
                'workflow_status' => 'resolved',
                'last_event_type' => 'billing.escalation.resolved',
                'timeline_count' => 2,
            ])
            ->assertJsonFragment([
                'domain' => 'finance',
                'code' => 'finance.stale_acknowledged',
                'workflow_status' => 'resolved',
                'last_event_type' => 'finance.escalation.resolved',
                'timeline_count' => 2,
            ]);
    }

    public function test_cashier_cannot_read_unified_operations_escalation_history(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-escalations/history')
            ->assertStatus(403);
    }

    private function seedUnifiedHistory(User $admin): void
    {
        $billingStateId = DB::table('billing_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'billing.failed_backlog',
            'status' => 'resolved',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-12 08:30:00',
            'acknowledgement_note' => 'Soporte revisando retry desde tablero unificado.',
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => '2026-03-12 10:00:00',
            'resolution_note' => 'Caso billing cerrado desde cola cross-domain.',
            'last_seen_at' => '2026-03-12 10:00:00',
            'created_at' => '2026-03-12 08:30:00',
            'updated_at' => '2026-03-12 10:00:00',
        ]);

        $financeStateId = DB::table('finance_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'finance.stale_acknowledged',
            'status' => 'resolved',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-12 09:00:00',
            'acknowledgement_note' => 'Tesoreria tomó la alerta agregada.',
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => '2026-03-12 10:30:00',
            'resolution_note' => 'Caso financiero cerrado desde cola cross-domain.',
            'last_seen_at' => '2026-03-12 10:30:00',
            'created_at' => '2026-03-12 09:00:00',
            'updated_at' => '2026-03-12 10:30:00',
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'billing',
                'event_type' => 'billing.escalation.acknowledged',
                'aggregate_type' => 'billing_escalation_state',
                'aggregate_id' => $billingStateId,
                'summary' => 'Billing escalation billing.failed_backlog acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'billing.failed_backlog',
                    'status' => 'acknowledged',
                    'note' => 'Soporte revisando retry desde tablero unificado.',
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
                'aggregate_id' => $billingStateId,
                'summary' => 'Billing escalation billing.failed_backlog resolved.',
                'metadata' => json_encode([
                    'escalation_code' => 'billing.failed_backlog',
                    'status' => 'resolved',
                    'note' => 'Caso billing cerrado desde cola cross-domain.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:00:00',
                'created_at' => '2026-03-12 10:00:00',
                'updated_at' => '2026-03-12 10:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.acknowledged',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $financeStateId,
                'summary' => 'Finance escalation finance.stale_acknowledged acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.stale_acknowledged',
                    'status' => 'acknowledged',
                    'note' => 'Tesoreria tomó la alerta agregada.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 09:00:00',
                'created_at' => '2026-03-12 09:00:00',
                'updated_at' => '2026-03-12 09:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.resolved',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $financeStateId,
                'summary' => 'Finance escalation finance.stale_acknowledged resolved.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.stale_acknowledged',
                    'status' => 'resolved',
                    'note' => 'Caso financiero cerrado desde cola cross-domain.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:30:00',
                'created_at' => '2026-03-12 10:30:00',
                'updated_at' => '2026-03-12 10:30:00',
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

    private function seedBillingScenario(User $admin): void
    {
        DB::table('billing_provider_profiles')->insert([
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'environment' => 'live',
            'default_outcome' => 'accepted',
            'credentials' => null,
            'health_status' => 'healthy',
            'health_checked_at' => now()->subHour(),
            'health_message' => 'Provider fake_sunat is reachable in live mode.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failedDay11 = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-OPS-HIST-11',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-11 10:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-11 10:12:00',
            providerEnvironment: 'sandbox',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-OPS-HIST-12',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-12 11:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-12 11:04:00',
            providerEnvironment: 'live',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-OPS-HIST-12-REPLAY',
            voucherStatus: 'pending',
            eventStatus: 'pending',
            createdAt: '2026-03-12 12:00:00',
            attemptStatus: null,
            attemptCreatedAt: null,
            providerEnvironment: 'live',
            replayedFromEventId: $failedDay11['event_id'],
        );
    }

    private function seedFinanceScenario(User $admin): void
    {
        $customerId = $this->seedCustomer(10, 'Cliente Historial Unificado');
        $supplierId = $this->seedSupplier(10, '20181818181', 'Proveedor Historial Unificado');

        $brokenReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-HIST-BROKEN');
        $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-14 00:00:00', 'SALE-FIN-HIST-UPCOMING');
        $acknowledgedPayableId = $this->seedPayable(10, $admin->id, $supplierId, 34.00, '2026-02-04 00:00:00', 'PUR-FIN-HIST-ACK');

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => $brokenReceivableId,
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente prometio pagar ayer.',
            'promised_amount' => 20.00,
            'outstanding_snapshot' => 20.00,
            'promised_at' => '2026-03-11 00:00:00',
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
        ]);

        DB::table('finance_operation_states')->insert([
            'tenant_id' => 10,
            'entity_type' => 'payable',
            'entity_id' => $acknowledgedPayableId,
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-11 08:00:00',
            'acknowledgement_note' => 'Tesoreria ya tomo el caso.',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
            'last_seen_at' => '2026-03-11 08:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 08:00:00',
        ]);
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

    private function seedCustomer(int $tenantId, string $name): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => 'dni',
            'document_number' => (string) random_int(10000000, 99999999),
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSupplier(int $tenantId, string $taxId, string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReceivable(
        int $tenantId,
        int $userId,
        int $customerId,
        float $amount,
        string $dueAt,
        string $reference,
    ): int {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => $amount,
            'gross_cost' => round($amount * 0.4, 2),
            'gross_margin' => round($amount * 0.6, 2),
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);

        return DB::table('sale_receivables')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'outstanding_amount' => $amount,
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);
    }

    private function seedPayable(
        int $tenantId,
        int $userId,
        int $supplierId,
        float $amount,
        string $dueAt,
        string $reference,
    ): int {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $amount,
            'received_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);

        return DB::table('purchase_payables')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'outstanding_amount' => $amount,
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);
    }
}
