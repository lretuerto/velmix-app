<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsControlTowerHistoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_operations_control_tower_history_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $this->seedBaseDailySlice($admin);
        $this->seedCompareDailySlice($admin);
        $this->seedBillingProgression($admin);
        $this->seedFinanceProgression($admin);
        $this->seedForeignCriticalSlice($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/history?date=2026-03-12&days=2&billing_days=2&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.history_window.days', 2)
            ->assertJsonPath('data.history_window.start_date', '2026-03-11')
            ->assertJsonPath('data.history_window.end_date', '2026-03-12')
            ->assertJsonPath('data.summary.status_breakdown.ok_count', 0)
            ->assertJsonPath('data.summary.status_breakdown.warning_count', 0)
            ->assertJsonPath('data.summary.status_breakdown.critical_count', 2)
            ->assertJsonPath('data.summary.worst_day.date', '2026-03-12')
            ->assertJsonPath('data.summary.maxima.cash_discrepancy_total', 0.5)
            ->assertJsonPath('data.summary.maxima.billing_failed_backlog_count', 1)
            ->assertJsonPath('data.summary.maxima.finance_overdue_total', 20)
            ->assertJsonPath('data.timeline.0.date', '2026-03-11')
            ->assertJsonPath('data.timeline.0.overall_status', 'critical')
            ->assertJsonPath('data.timeline.0.billing_failed_backlog_count', 1)
            ->assertJsonPath('data.timeline.0.finance_overdue_total', 0)
            ->assertJsonPath('data.timeline.1.date', '2026-03-12')
            ->assertJsonPath('data.timeline.1.overall_status', 'critical')
            ->assertJsonPath('data.timeline.1.billing_failed_backlog_count', 1)
            ->assertJsonPath('data.timeline.1.finance_overdue_total', 20)
            ->assertJsonPath('data.timeline.1.report_path', '/reports/operations-control-tower?date=2026-03-12');
    }

    public function test_compares_operations_control_tower_between_two_days(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $this->seedBaseDailySlice($admin);
        $this->seedCompareDailySlice($admin);
        $this->seedBillingProgression($admin);
        $this->seedFinanceProgression($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/compare?base_date=2026-03-11&compare_date=2026-03-12&billing_days=2&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.base.date', '2026-03-11')
            ->assertJsonPath('data.base.executive_summary.overall_status', 'critical')
            ->assertJsonPath('data.compare.date', '2026-03-12')
            ->assertJsonPath('data.compare.executive_summary.overall_status', 'critical')
            ->assertJsonPath('data.delta.sales_completed_total', 24)
            ->assertJsonPath('data.delta.collections_total', 9)
            ->assertJsonPath('data.delta.cash_discrepancy_total', 0.5)
            ->assertJsonPath('data.delta.billing_pending_backlog_count', 0)
            ->assertJsonPath('data.delta.billing_failed_backlog_count', 0)
            ->assertJsonPath('data.delta.finance_overdue_total', 20)
            ->assertJsonPath('data.delta.finance_broken_promise_count', 1)
            ->assertJsonPath('data.delta.operations_open_alert_count', 5)
            ->assertJsonPath('data.overall_status_change.from', 'critical')
            ->assertJsonPath('data.overall_status_change.to', 'critical')
            ->assertJsonPath('data.overall_status_change.changed', false)
            ->assertJsonPath('data.gate_changes.sales_cash.from', 'ok')
            ->assertJsonPath('data.gate_changes.sales_cash.to', 'warning')
            ->assertJsonPath('data.gate_changes.sales_cash.changed', true)
            ->assertJsonPath('data.gate_changes.billing.from', 'critical')
            ->assertJsonPath('data.gate_changes.billing.to', 'critical')
            ->assertJsonPath('data.gate_changes.billing.changed', false)
            ->assertJsonPath('data.gate_changes.finance.from', 'ok')
            ->assertJsonPath('data.gate_changes.finance.to', 'critical')
            ->assertJsonPath('data.gate_changes.finance.changed', true)
            ->assertJsonPath('data.gate_changes.operations.from', 'critical')
            ->assertJsonPath('data.gate_changes.operations.to', 'critical')
            ->assertJsonPath('data.gate_changes.operations.changed', false);
    }

    public function test_cashier_cannot_read_operations_control_tower_history_or_compare(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/history')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/compare?base_date=2026-03-11&compare_date=2026-03-12')
            ->assertStatus(403);
    }

    private function seedBaseDailySlice(User $admin): void
    {
        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => null,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-CT-BASE',
            'status' => 'completed',
            'payment_method' => 'cash',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 30.00,
            'gross_cost' => 12.00,
            'gross_margin' => 18.00,
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
        ]);

        DB::table('cash_sessions')->insert([
            'tenant_id' => 10,
            'opened_by_user_id' => $admin->id,
            'closed_by_user_id' => $admin->id,
            'opening_amount' => 50.00,
            'expected_amount' => 80.00,
            'counted_amount' => 80.00,
            'discrepancy_amount' => 0.00,
            'status' => 'closed',
            'opened_at' => '2026-03-11 08:00:00',
            'closed_at' => '2026-03-11 18:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 18:00:00',
        ]);
    }

    private function seedCompareDailySlice(User $admin): void
    {
        DB::table('sales')->insert([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => null,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-CT-COMPARE',
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

        $customerId = $this->seedCustomer(10, 'Cliente CT Cobranza');
        $creditSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'customer_id' => $customerId,
            'cancelled_by_user_id' => null,
            'reference' => 'SALE-CT-COLLECTION',
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
            'reference' => 'COBRO-CT-001',
            'paid_at' => '2026-03-12 14:00:00',
            'created_at' => '2026-03-12 14:00:00',
            'updated_at' => '2026-03-12 14:00:00',
        ]);
    }

    private function seedBillingProgression(User $admin): void
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

        $failed = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-CT-BILL-FAIL',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-12 10:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-12 10:12:00',
            providerEnvironment: 'sandbox',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-CT-BILL-OK',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-11 10:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-11 10:05:00',
            providerEnvironment: 'live',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-CT-BILL-REPLAY',
            voucherStatus: 'pending',
            eventStatus: 'pending',
            createdAt: '2026-03-12 12:00:00',
            attemptStatus: null,
            attemptCreatedAt: null,
            providerEnvironment: 'live',
            replayedFromEventId: $failed['event_id'],
        );
    }

    private function seedFinanceProgression(User $admin): void
    {
        $customerId = $this->seedCustomer(10, 'Cliente CT Finanzas');
        $receivableId = $this->seedReceivable(
            10,
            $admin->id,
            $customerId,
            20.00,
            '2026-03-11 00:00:00',
            'SALE-CT-FINANCE',
        );

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => $receivableId,
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente prometio pagar hoy',
            'promised_amount' => 20.00,
            'outstanding_snapshot' => 20.00,
            'promised_at' => '2026-03-12 00:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 08:00:00',
        ]);
    }

    private function seedForeignCriticalSlice(User $admin): void
    {
        $this->seedVoucherEvent(
            tenantId: 20,
            userId: $admin->id,
            reference: 'SALE-CT-OTHER-TENANT',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-12 15:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-12 15:03:00',
            providerEnvironment: 'live',
        );
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
}
