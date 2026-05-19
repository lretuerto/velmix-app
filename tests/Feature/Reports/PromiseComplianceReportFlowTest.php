<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromiseComplianceReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_promise_compliance_summary_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $customerId = $this->seedCustomer(10, 'Cliente Promesa');
        $foreignCustomerId = $this->seedCustomer(20, 'Cliente Fuera');
        $supplierId = $this->seedSupplier(10, '20151515151', 'Proveedor Promesa');
        $foreignSupplierId = $this->seedSupplier(20, '20252525252', 'Proveedor Fuera');

        $brokenReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, 20.00, now()->subDays(2), 'SALE-PROM-BROKEN');
        $pendingReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 15.00, 15.00, now()->addDays(4), 'SALE-PROM-PENDING');
        $fulfilledReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 12.00, 0.00, now()->subDays(1), 'SALE-PROM-FULFILLED');
        $this->seedReceivable(20, $admin->id, $foreignCustomerId, 80.00, 80.00, now()->subDays(3), 'SALE-PROM-FOREIGN');

        $brokenPayableId = $this->seedPayable(10, $supplierId, 18.00, 18.00, now()->subDays(3), 'PUR-PROM-BROKEN');
        $pendingPayableId = $this->seedPayable(10, $supplierId, 11.00, 11.00, now()->addDays(2), 'PUR-PROM-PENDING');
        $fulfilledPayableId = $this->seedPayable(10, $supplierId, 9.00, 0.00, now()->subDays(1), 'PUR-PROM-FULFILLED');
        $this->seedPayable(20, $foreignSupplierId, 70.00, 70.00, now()->subDays(5), 'PUR-PROM-FOREIGN');

        DB::table('sale_receivable_follow_ups')->insert([
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $brokenReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Promete pagar ayer',
                'promised_amount' => 10.00,
                'outstanding_snapshot' => 20.00,
                'promised_at' => '2026-03-10 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $pendingReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Promete pagar esta semana',
                'promised_amount' => 8.00,
                'outstanding_snapshot' => 15.00,
                'promised_at' => '2026-03-13 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'sale_receivable_id' => $fulfilledReceivableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Promesa ya cumplida',
                'promised_amount' => 12.00,
                'outstanding_snapshot' => 12.00,
                'promised_at' => '2026-03-09 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 20,
                'sale_receivable_id' => DB::table('sale_receivables')->where('tenant_id', 20)->value('id'),
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Promesa ajena',
                'promised_amount' => 10.00,
                'outstanding_snapshot' => 80.00,
                'promised_at' => '2026-03-09 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('purchase_payable_follow_ups')->insert([
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $brokenPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago a proveedor incumplido',
                'promised_amount' => 18.00,
                'outstanding_snapshot' => 18.00,
                'promised_at' => '2026-03-09 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $pendingPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Pago comprometido para manana',
                'promised_amount' => 6.00,
                'outstanding_snapshot' => 11.00,
                'promised_at' => '2026-03-12 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'purchase_payable_id' => $fulfilledPayableId,
                'user_id' => $admin->id,
                'type' => 'promise',
                'note' => 'Promesa de pago ya cumplida',
                'promised_amount' => 9.00,
                'outstanding_snapshot' => 9.00,
                'promised_at' => '2026-03-09 00:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/promise-compliance?date=2026-03-11&limit=2')
            ->assertOk()
            ->assertJsonPath('data.summary.receivables.broken.count', 1)
            ->assertJsonPath('data.summary.receivables.pending.count', 1)
            ->assertJsonPath('data.summary.receivables.fulfilled.count', 1)
            ->assertJsonPath('data.summary.payables.broken.count', 1)
            ->assertJsonPath('data.summary.payables.pending.count', 1)
            ->assertJsonPath('data.summary.payables.fulfilled.count', 1)
            ->assertJsonPath('data.summary.combined.broken.count', 2)
            ->assertJsonPath('data.broken_receivables.0.sale_reference', 'SALE-PROM-BROKEN')
            ->assertJsonPath('data.broken_receivables.0.status', 'broken')
            ->assertJsonPath('data.pending_receivables.0.sale_reference', 'SALE-PROM-PENDING')
            ->assertJsonPath('data.pending_receivables.0.status', 'pending')
            ->assertJsonPath('data.fulfilled_receivables.0.sale_reference', 'SALE-PROM-FULFILLED')
            ->assertJsonPath('data.fulfilled_receivables.0.status', 'fulfilled')
            ->assertJsonPath('data.broken_payables.0.receipt_reference', 'PUR-PROM-BROKEN')
            ->assertJsonPath('data.pending_payables.0.receipt_reference', 'PUR-PROM-PENDING')
            ->assertJsonPath('data.fulfilled_payables.0.receipt_reference', 'PUR-PROM-FULFILLED')
            ->assertJsonMissing(['sale_reference' => 'SALE-PROM-FOREIGN'])
            ->assertJsonMissing(['receipt_reference' => 'PUR-PROM-FOREIGN']);
    }

    public function test_cashier_cannot_read_promise_compliance_summary(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/promise-compliance')
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
        float $totalAmount,
        float $outstandingAmount,
        $dueAt,
        string $reference,
    ): int {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => $totalAmount,
            'gross_cost' => round($totalAmount * 0.4, 2),
            'gross_margin' => round($totalAmount * 0.6, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('sale_receivables')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => $totalAmount,
            'paid_amount' => round($totalAmount - $outstandingAmount, 2),
            'outstanding_amount' => $outstandingAmount,
            'status' => $outstandingAmount <= 0 ? 'paid' : 'pending',
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPayable(
        int $tenantId,
        int $supplierId,
        float $totalAmount,
        float $outstandingAmount,
        $dueAt,
        string $reference,
    ): int {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => User::factory()->create()->id,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $totalAmount,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('purchase_payables')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => $totalAmount,
            'paid_amount' => round($totalAmount - $outstandingAmount, 2),
            'outstanding_amount' => $outstandingAmount,
            'status' => $outstandingAmount <= 0 ? 'paid' : 'pending',
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
