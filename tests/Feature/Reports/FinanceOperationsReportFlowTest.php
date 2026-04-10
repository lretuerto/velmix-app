<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceOperationsReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_operations_summary_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $customerId = $this->seedCustomer(10, 'Cliente Finanzas');
        $foreignCustomerId = $this->seedCustomer(20, 'Cliente Externo');
        $supplierId = $this->seedSupplier(10, '20181818181', 'Proveedor Finanzas');
        $foreignSupplierId = $this->seedSupplier(20, '20292929292', 'Proveedor Externo');

        $overdueReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-OD');
        $currentReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-15 00:00:00', 'SALE-FIN-CURRENT');
        $this->seedReceivable(20, $admin->id, $foreignCustomerId, 80.00, '2026-03-09 00:00:00', 'SALE-FIN-FOREIGN');

        $overduePayableId = $this->seedPayable(10, $admin->id, $supplierId, 18.00, '2026-03-09 00:00:00', 'PUR-FIN-OD');
        $upcomingPayableId = $this->seedPayable(10, $admin->id, $supplierId, 22.00, '2026-03-14 00:00:00', 'PUR-FIN-UP');
        $this->seedPayable(20, $admin->id, $foreignSupplierId, 90.00, '2026-03-08 00:00:00', 'PUR-FIN-FOREIGN');

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

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations?date=2026-03-12&days_ahead=7&limit=5&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.receivables.exposure.count', 2)
            ->assertJsonPath('data.receivables.exposure.outstanding_total', 35)
            ->assertJsonPath('data.receivables.exposure.overdue_count', 1)
            ->assertJsonPath('data.receivables.exposure.overdue_total', 20)
            ->assertJsonPath('data.receivables.exposure.current_total', 15)
            ->assertJsonPath('data.receivables.promise_compliance.broken_count', 1)
            ->assertJsonPath('data.receivables.promise_compliance.broken_total', 20)
            ->assertJsonPath('data.receivables.promise_compliance.pending_count', 0)
            ->assertJsonPath('data.receivables.follow_up_health.missing_count', 1)
            ->assertJsonPath('data.receivables.follow_up_health.missing_total', 15)
            ->assertJsonPath('data.receivables.follow_up_health.stale_count', 1)
            ->assertJsonPath('data.receivables.follow_up_health.stale_total', 20)
            ->assertJsonPath('data.payables.exposure.count', 2)
            ->assertJsonPath('data.payables.exposure.outstanding_total', 40)
            ->assertJsonPath('data.payables.exposure.overdue_count', 1)
            ->assertJsonPath('data.payables.exposure.overdue_total', 18)
            ->assertJsonPath('data.payables.promise_compliance.pending_count', 1)
            ->assertJsonPath('data.payables.promise_compliance.pending_total', 22)
            ->assertJsonPath('data.payables.follow_up_health.stale_count', 1)
            ->assertJsonPath('data.payables.follow_up_health.stale_total', 18)
            ->assertJsonPath('data.payables.follow_up_health.recent_count', 1)
            ->assertJsonPath('data.payables.follow_up_health.recent_total', 22)
            ->assertJsonPath('data.combined.outstanding_total', 75)
            ->assertJsonPath('data.combined.overdue_total', 38)
            ->assertJsonPath('data.combined.broken_promise_count', 1)
            ->assertJsonPath('data.combined.stale_follow_up_count', 2)
            ->assertJsonPath('data.combined.missing_follow_up_count', 1)
            ->assertJsonPath('data.workflow.open_count', 4)
            ->assertJsonPath('data.workflow.acknowledged_count', 0)
            ->assertJsonPath('data.workflow.resolved_count', 0)
            ->assertJsonPath('data.priority_queue.0.reference', 'SALE-FIN-OD')
            ->assertJsonPath('data.priority_queue.0.escalation_level', 'critical')
            ->assertJsonPath('data.priority_queue.0.workflow_status', 'open')
            ->assertJsonPath('data.priority_queue.1.reference', 'PUR-FIN-OD')
            ->assertJsonPath('data.priority_queue.1.escalation_level', 'attention');
    }

    public function test_cashier_cannot_read_finance_operations_summary(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations')
            ->assertStatus(403);
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
}
