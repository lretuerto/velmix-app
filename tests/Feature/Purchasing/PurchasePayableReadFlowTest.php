<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasePayableReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_payable_aging_summary_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $supplierId = $this->seedSupplier(10, '20161616161', 'Proveedor Aging');

        $this->seedPayable(10, $supplierId, 10.00, 0.00, 10.00, 'pending', now()->addDays(5), 'PUR-CURRENT');
        $this->seedPayable(10, $supplierId, 20.00, 5.00, 15.00, 'partial_paid', now()->subDays(10), 'PUR-OD-10');
        $this->seedPayable(10, $supplierId, 30.00, 0.00, 30.00, 'pending', now()->subDays(45), 'PUR-OD-45');
        $this->seedPayable(10, $supplierId, 40.00, 0.00, 40.00, 'pending', now()->subDays(75), 'PUR-OD-75');
        $this->seedPayable(10, $supplierId, 50.00, 50.00, 0.00, 'paid', now()->subDays(2), 'PUR-PAID');
        $foreignSupplierId = $this->seedSupplier(20, '20262626262', 'Proveedor 20');
        $this->seedPayable(20, $foreignSupplierId, 999.00, 0.00, 999.00, 'pending', now()->subDays(5), 'PUR-FOREIGN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/payables/aging')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.current.count', 1)
            ->assertJsonPath('data.summary.current.amount', 10)
            ->assertJsonPath('data.summary.overdue_1_30.count', 1)
            ->assertJsonPath('data.summary.overdue_1_30.amount', 15)
            ->assertJsonPath('data.summary.overdue_31_60.count', 1)
            ->assertJsonPath('data.summary.overdue_31_60.amount', 30)
            ->assertJsonPath('data.summary.overdue_61_plus.count', 1)
            ->assertJsonPath('data.summary.overdue_61_plus.amount', 40)
            ->assertJsonPath('data.summary.paid.count', 1)
            ->assertJsonPath('data.summary.paid.amount', 50);
    }

    public function test_reads_supplier_statement_for_current_tenant_only(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20171717171', 'Proveedor Estado');
        $receiptId = $this->seedReceipt(10, $warehouseUser->id, $supplierId, 'PUR-STMT-10', 25.00);
        $payableId = DB::table('purchase_payables')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => 20.00,
            'paid_amount' => 10.00,
            'outstanding_amount' => 10.00,
            'status' => 'partial_paid',
            'due_at' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_payments')->insert([
            'purchase_payable_id' => $payableId,
            'user_id' => $warehouseUser->id,
            'amount' => 10.00,
            'payment_method' => 'cash',
            'reference' => 'PAY-STMT-01',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $returnId = DB::table('purchase_returns')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'purchase_payable_id' => $payableId,
            'user_id' => $warehouseUser->id,
            'reference' => 'PRT-STMT-01',
            'status' => 'processed',
            'reason' => 'Producto dañado',
            'total_amount' => 5.00,
            'returned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_credits')->insert([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_payable_id' => $payableId,
            'purchase_return_id' => $returnId,
            'amount' => 2.00,
            'remaining_amount' => 2.00,
            'status' => 'available',
            'reference' => 'PRT-STMT-01-CREDIT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('supplier_credit_applications')->insert([
            'tenant_id' => 10,
            'supplier_credit_id' => DB::table('supplier_credits')->where('reference', 'PRT-STMT-01-CREDIT')->value('id'),
            'purchase_payable_id' => $payableId,
            'user_id' => $warehouseUser->id,
            'amount' => 1.50,
            'application_type' => 'manual',
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignUser = $this->seedUserWithRole(20, 'ALMACENERO');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/suppliers/{$supplierId}/statement")
            ->assertOk()
            ->assertJsonPath('data.supplier.name', 'Proveedor Estado')
            ->assertJsonPath('data.summary.receipts_total', 25)
            ->assertJsonPath('data.summary.payables_total', 20)
            ->assertJsonPath('data.summary.payments_total', 10)
            ->assertJsonPath('data.summary.returns_total', 5)
            ->assertJsonPath('data.summary.supplier_credits_total', 2)
            ->assertJsonPath('data.summary.supplier_credits_applied_total', 1.5)
            ->assertJsonPath('data.summary.outstanding_total', 10)
            ->assertJsonPath('data.receipts.0.reference', 'PUR-STMT-10')
            ->assertJsonPath('data.payments.0.reference', 'PAY-STMT-01')
            ->assertJsonPath('data.returns.0.reference', 'PRT-STMT-01')
            ->assertJsonPath('data.supplier_credits.0.reference', 'PRT-STMT-01-CREDIT')
            ->assertJsonPath('data.supplier_credit_applications.0.supplier_credit_reference', 'PRT-STMT-01-CREDIT');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/payables/{$payableId}")
            ->assertOk()
            ->assertJsonPath('data.supplier_credit_applied_amount', 1.5)
            ->assertJsonPath('data.supplier_credit_applications.0.supplier_credit_reference', 'PRT-STMT-01-CREDIT');

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/purchases/suppliers/{$supplierId}/statement")
            ->assertStatus(404);
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

    private function seedReceipt(int $tenantId, int $userId, int $supplierId, string $reference, float $totalAmount): int
    {
        return DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $totalAmount,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPayable(
        int $tenantId,
        int $supplierId,
        float $totalAmount,
        float $paidAmount,
        float $outstandingAmount,
        string $status,
        \Carbon\Carbon $dueAt,
        string $receiptReference,
    ): void {
        $userId = User::factory()->create()->id;
        $receiptId = $this->seedReceipt($tenantId, $userId, $supplierId, $receiptReference, $totalAmount);

        DB::table('purchase_payables')->insert([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => $outstandingAmount,
            'status' => $status,
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
