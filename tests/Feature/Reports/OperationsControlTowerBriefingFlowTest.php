<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsControlTowerBriefingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_operations_control_tower_briefing_with_latest_snapshot_context(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedDailySlice($admin);
        $this->seedBillingSlice($admin);
        $this->seedFinanceSlice($admin);

        $snapshotId = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/operations-control-tower/snapshots', [
                'date' => '2026-03-12',
                'billing_days' => 3,
                'finance_days_ahead' => 7,
                'priority_limit' => 5,
                'failure_limit' => 5,
                'stale_follow_up_days' => 3,
                'label' => 'baseline',
            ])
            ->assertOk()
            ->json('data.id');

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-BRIEFING-EXTRA-FAIL',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-12 16:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-12 16:05:00',
            providerEnvironment: 'live',
        );

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.executive_summary.overall_status', 'critical')
            ->assertJsonPath('data.executive_summary.billing_failed_backlog_count', 2)
            ->assertJsonPath('data.snapshot_context.snapshot.id', $snapshotId)
            ->assertJsonPath('data.snapshot_context.compare.mode', 'live')
            ->assertJsonPath('data.snapshot_context.compare.delta.billing_failed_backlog_count', 1)
            ->assertJsonPath('data.snapshot_context.requested_windows_match_snapshot_windows', true)
            ->assertJsonPath('data.highlights.snapshot_drift.movement', 'worsened')
            ->assertJsonPath('data.paths.export_markdown', '/reports/operations-control-tower/briefing/export?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3&snapshot_id='.$snapshotId.'&format=markdown');

        $insights = $response->json('data.highlights.insights');

        $this->assertContains('Current briefing is worsened versus snapshot '.$snapshotId.'.', $insights);
    }

    public function test_admin_can_export_operations_control_tower_briefing_in_markdown_and_json(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedDailySlice($admin);
        $this->seedBillingSlice($admin);
        $this->seedFinanceSlice($admin);

        $snapshotId = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/operations-control-tower/snapshots', [
                'date' => '2026-03-12',
                'billing_days' => 3,
                'finance_days_ahead' => 7,
                'priority_limit' => 5,
                'failure_limit' => 5,
                'stale_follow_up_days' => 3,
            ])
            ->assertOk()
            ->json('data.id');

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-BRIEFING-FAIL',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-12 17:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-12 17:04:00',
            providerEnvironment: 'live',
        );

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing/export?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3&snapshot_id='.$snapshotId.'&format=markdown')
            ->assertOk()
            ->assertJsonPath('data.format', 'markdown')
            ->assertJsonPath('data.content', fn (string $content) => str_contains($content, '# Operations Control Tower Briefing')
                && str_contains($content, '- Date: 2026-03-12')
                && str_contains($content, '- Snapshot ID: '.$snapshotId)
                && str_contains($content, '- Movement: worsened'));

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing/export?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3&snapshot_id='.$snapshotId.'&format=json')
            ->assertOk()
            ->assertJsonPath('data.format', 'json')
            ->assertJsonPath('data.payload.snapshot_context.snapshot.id', $snapshotId)
            ->assertJsonPath('data.payload.highlights.snapshot_drift.movement', 'worsened');
    }

    public function test_briefing_uses_requested_windows_for_snapshot_drift_when_they_do_not_match_snapshot(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedDailySlice($admin);
        $this->seedBillingSlice($admin);
        $this->seedFinanceSlice($admin);

        $snapshotId = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/operations-control-tower/snapshots', [
                'date' => '2026-03-12',
                'billing_days' => 3,
                'finance_days_ahead' => 7,
                'priority_limit' => 5,
                'failure_limit' => 5,
                'stale_follow_up_days' => 3,
                'label' => 'baseline 3d',
            ])
            ->assertOk()
            ->json('data.id');

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing?date=2026-03-12&history_days=3&billing_days=1&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3&snapshot_id='.$snapshotId)
            ->assertOk()
            ->assertJsonPath('data.snapshot_context.requested_windows_match_snapshot_windows', false)
            ->assertJsonPath('data.snapshot_context.compare.windows.match', false)
            ->assertJsonPath('data.snapshot_context.compare.windows.compare.billing_days', 1);

        $payload = $response->json('data');

        $this->assertSame(
            $payload['executive_summary'],
            $payload['snapshot_context']['compare']['compare']['executive_summary'],
        );
    }

    public function test_briefing_can_be_read_without_snapshot_context(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedDailySlice($admin);
        $this->seedBillingSlice($admin);
        $this->seedFinanceSlice($admin);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.snapshot_context', null)
            ->assertJsonPath('data.paths.self', '/reports/operations-control-tower/briefing?date=2026-03-12&history_days=3&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3')
            ->assertJsonPath('data.current.paths.briefing', '/reports/operations-control-tower/briefing?date=2026-03-12&history_days=7&billing_days=3&finance_days_ahead=7&priority_limit=5&failure_limit=5&stale_follow_up_days=3');

        $insights = $response->json('data.highlights.insights');

        $this->assertContains('No persisted snapshot is available on or before the requested date.', $insights);
    }

    public function test_cashier_cannot_read_or_export_operations_control_tower_briefing(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/operations-control-tower/briefing/export')
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_force_other_tenant_snapshot_in_briefing(): void
    {
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');
        $this->seedDailySlice($admin10);
        $this->seedBillingSlice($admin10);
        $this->seedFinanceSlice($admin10);

        $snapshotId = $this->actingAs($admin10)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/reports/operations-control-tower/snapshots', ['date' => '2026-03-12'])
            ->assertOk()
            ->json('data.id');

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson('/reports/operations-control-tower/briefing?snapshot_id='.$snapshotId)
            ->assertStatus(404);
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
