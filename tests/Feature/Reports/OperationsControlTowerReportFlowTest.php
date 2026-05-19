<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsControlTowerReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_operations_control_tower_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $this->seedDailySlice($admin);
        $failedDay11 = $this->seedBillingSlice($admin);
        $this->seedFinanceSlice($admin);
        $this->seedForeignBillingSlice($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower?date=2026-03-12&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.executive_summary.overall_status', 'critical')
            ->assertJsonPath('data.executive_summary.critical_gate_count', 3)
            ->assertJsonPath('data.executive_summary.warning_gate_count', 1)
            ->assertJsonPath('data.executive_summary.sales_completed_total', 99)
            ->assertJsonPath('data.executive_summary.collections_total', 9)
            ->assertJsonPath('data.executive_summary.cash_discrepancy_total', 0.5)
            ->assertJsonPath('data.executive_summary.billing_pending_backlog_count', 1)
            ->assertJsonPath('data.executive_summary.billing_failed_backlog_count', 1)
            ->assertJsonPath('data.executive_summary.finance_overdue_total', 38)
            ->assertJsonPath('data.executive_summary.finance_broken_promise_count', 1)
            ->assertJsonPath('data.executive_summary.operations_open_alert_count', 9)
            ->assertJsonPath('data.health_gates.sales_cash.status', 'warning')
            ->assertJsonPath('data.health_gates.sales_cash.metric_snapshot.discrepancy_total', 0.5)
            ->assertJsonPath('data.health_gates.billing.status', 'critical')
            ->assertJsonPath('data.health_gates.billing.metric_snapshot.failed_backlog_count', 1)
            ->assertJsonPath('data.health_gates.finance.status', 'critical')
            ->assertJsonPath('data.health_gates.finance.metric_snapshot.overdue_total', 38)
            ->assertJsonPath('data.health_gates.operations.status', 'critical')
            ->assertJsonPath('data.paths.operations_escalations', '/reports/operations-escalations?date=2026-03-12&billing_days=3&finance_days_ahead=7&limit=5&stale_follow_up_days=3')
            ->assertJsonPath('data.action_center.operations_queue.0.code', 'billing.failed_backlog')
            ->assertJsonPath('data.action_center.finance_priority_queue.0.reference', 'SALE-FIN-OD')
            ->assertJsonPath('data.action_center.billing_recent_failures.0.event_id', $failedDay11['event_id'])
            ->assertJsonPath('data.slices.daily.sales.completed_count', 4)
            ->assertJsonPath('data.slices.daily.collections.total_amount', 9)
            ->assertJsonPath('data.slices.billing.executive_summary.replay_backlog_count', 1)
            ->assertJsonPath('data.slices.finance.combined.missing_follow_up_count', 1)
            ->assertJsonPath('data.slices.operations.workflow_metrics.active_count', 9)
            ->assertJsonPath('data.slices.operations.workflow_metrics.acknowledged_count', 0);
    }

    public function test_cashier_cannot_read_operations_control_tower(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower')
            ->assertStatus(403);
    }

    private function seedDailySlice(User $admin): void
    {
        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => null,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-DAILY-OPS',
            'status' => 'completed',
            'payment_method' => 'cash',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 40.00,
            'gross_cost' => 20.00,
            'gross_margin' => 20.00,
            'created_at' => '2026-03-12 09:00:00',
            'updated_at' => '2026-03-12 09:00:00',
        ]);

        DB::table('cash_sessions')->insert([
            'tenant_id' => 10,
            'opened_by_user_id' => $admin->id,
            'closed_by_user_id' => $admin->id,
            'opening_amount' => 100.00,
            'expected_amount' => 140.00,
            'counted_amount' => 140.50,
            'discrepancy_amount' => 0.50,
            'status' => 'closed',
            'opened_at' => '2026-03-12 08:00:00',
            'closed_at' => '2026-03-12 18:00:00',
            'created_at' => '2026-03-12 08:00:00',
            'updated_at' => '2026-03-12 18:00:00',
        ]);

        $customerId = $this->seedCustomer(10, 'Cliente Cobranza Ops');
        $creditSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-CREDIT-COLLECTIONS',
            'status' => 'completed',
            'payment_method' => 'credit',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 9.00,
            'gross_cost' => 4.00,
            'gross_margin' => 5.00,
            'created_at' => '2026-03-12 13:50:00',
            'updated_at' => '2026-03-12 13:50:00',
        ]);

        $receivableId = DB::table('sale_receivables')->insertGetId([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $creditSaleId,
            'total_amount' => 9.00,
            'paid_amount' => 9.00,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
            'due_at' => '2026-03-20 00:00:00',
            'created_at' => '2026-03-12 13:50:00',
            'updated_at' => '2026-03-12 14:00:00',
        ]);

        DB::table('sale_receivable_payments')->insert([
            'sale_receivable_id' => $receivableId,
            'user_id' => $admin->id,
            'amount' => 9.00,
            'payment_method' => 'cash',
            'reference' => 'COBRO-OPS-001',
            'paid_at' => '2026-03-12 14:00:00',
            'created_at' => '2026-03-12 14:00:00',
            'updated_at' => '2026-03-12 14:00:00',
        ]);
    }

    private function seedBillingSlice(User $admin): array
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

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-10',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-10 09:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-10 09:05:00',
            providerEnvironment: 'live',
        );

        $failedDay11 = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-11',
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
            reference: 'SALE-BILL-OPS-12',
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
            reference: 'SALE-BILL-OPS-12-REPLAY',
            voucherStatus: 'pending',
            eventStatus: 'pending',
            createdAt: '2026-03-12 12:00:00',
            attemptStatus: null,
            attemptCreatedAt: null,
            providerEnvironment: 'live',
            replayedFromEventId: $failedDay11['event_id'],
        );

        return $failedDay11;
    }

    private function seedForeignBillingSlice(User $admin): void
    {
        $this->seedVoucherEvent(
            tenantId: 20,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-OTHER',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-12 09:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-12 09:03:00',
            providerEnvironment: 'live',
        );
    }

    private function seedFinanceSlice(User $admin): void
    {
        $customerId = $this->seedCustomer(10, 'Cliente Finanzas Ops');
        $supplierId = $this->seedSupplier(10, '20181818181', 'Proveedor Finanzas Ops');

        $overdueReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-OD');
        $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-15 00:00:00', 'SALE-FIN-CURRENT');
        $overduePayableId = $this->seedPayable(10, $admin->id, $supplierId, 18.00, '2026-03-09 00:00:00', 'PUR-FIN-OD');
        $upcomingPayableId = $this->seedPayable(10, $admin->id, $supplierId, 22.00, '2026-03-14 00:00:00', 'PUR-FIN-UP');

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => $overdueReceivableId,
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente prometio pagar ayer',
            'promised_amount' => 20.00,
            'outstanding_snapshot' => 20.00,
            'promised_at' => '2026-03-11 00:00:00',
            'created_at' => '2026-03-08 09:00:00',
            'updated_at' => '2026-03-08 09:00:00',
        ]);

        DB::table('purchase_payable_follow_ups')->insert([
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $overduePayableId,
                'user_id' => $admin->id,
                'type' => 'note',
                'note' => 'Pago proveedor sigue pendiente',
                'promised_amount' => null,
                'outstanding_snapshot' => null,
                'promised_at' => null,
                'created_at' => '2026-03-07 10:00:00',
                'updated_at' => '2026-03-07 10:00:00',
            ],
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $upcomingPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago proveedor programado',
                'promised_amount' => 22.00,
                'outstanding_snapshot' => 22.00,
                'promised_at' => '2026-03-13 00:00:00',
                'created_at' => '2026-03-11 12:00:00',
                'updated_at' => '2026-03-11 12:00:00',
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
}
