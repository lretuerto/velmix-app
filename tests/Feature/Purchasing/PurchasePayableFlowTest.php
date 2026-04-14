<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasePayableFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_reads_purchase_payables_for_current_tenant(): void
    {
        [$admin, $payableId] = $this->seedPayableScenario(10, 'ADMIN');
        $this->seedPayableScenario(20, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/payables')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $payableId,
                'status' => 'pending',
            ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/payables/{$payableId}")
            ->assertOk()
            ->assertJsonPath('data.id', $payableId)
            ->assertJsonPath('data.purchase_receipt.reference', 'PUR-PAY-10')
            ->assertJsonPath('data.outstanding_amount', 30);
    }

    public function test_admin_can_register_partial_and_full_supplier_payment(): void
    {
        [$admin, $payableId] = $this->seedPayableScenario(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 10,
                'payment_method' => 'bank_transfer',
                'reference' => 'TRX-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'partial_paid')
            ->assertJsonPath('data.outstanding_amount', 20);

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'paid_amount' => 10.00,
            'outstanding_amount' => 20.00,
            'status' => 'partial_paid',
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 20,
                'payment_method' => 'cash',
                'reference' => 'CAJA-002',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.outstanding_amount', 0);

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'paid_amount' => 30.00,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
        ]);
    }

    public function test_admin_can_apply_supplier_credit_manually_to_purchase_payable(): void
    {
        [$admin, $payableId, $supplierId] = $this->seedPayableScenario(10, 'ADMIN');
        $creditId = DB::table('supplier_credits')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_payable_id' => null,
            'purchase_return_id' => $this->seedPurchaseReturn(10, $supplierId, $admin->id),
            'amount' => 18.00,
            'remaining_amount' => 18.00,
            'status' => 'available',
            'reference' => 'SUP-CREDIT-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/apply-credits", [
                'amount' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('data.applied_amount', 12)
            ->assertJsonPath('data.outstanding_amount', 18)
            ->assertJsonPath('data.status', 'partial_paid');

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'paid_amount' => 12.00,
            'outstanding_amount' => 18.00,
            'status' => 'partial_paid',
        ]);

        $this->assertDatabaseHas('supplier_credits', [
            'id' => $creditId,
            'remaining_amount' => 6.00,
            'status' => 'partially_applied',
        ]);

        $this->assertDatabaseHas('supplier_credit_applications', [
            'tenant_id' => 10,
            'supplier_credit_id' => $creditId,
            'purchase_payable_id' => $payableId,
            'amount' => 12.00,
            'application_type' => 'manual',
        ]);
    }

    public function test_receipt_auto_applies_available_supplier_credit_to_new_payable(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => 10,
            'tax_id' => '20101122334',
            'name' => 'Proveedor AutoCredito',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseReturnId = $this->seedPurchaseReturn(10, $supplierId, $admin->id);
        $creditId = DB::table('supplier_credits')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_payable_id' => null,
            'purchase_return_id' => $purchaseReturnId,
            'amount' => 30.00,
            'remaining_amount' => 30.00,
            'status' => 'available',
            'reference' => 'SUP-CREDIT-AUTO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 8,
                    'unit_cost' => 2.50,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.supplier_credit_applied_amount', 20);

        $payableId = $response->json('data.purchase_payable_id');

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'total_amount' => 20.00,
            'paid_amount' => 20.00,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('supplier_credits', [
            'id' => $creditId,
            'remaining_amount' => 10.00,
            'status' => 'partially_applied',
        ]);

        $this->assertDatabaseHas('supplier_credit_applications', [
            'tenant_id' => 10,
            'supplier_credit_id' => $creditId,
            'purchase_payable_id' => $payableId,
            'amount' => 20.00,
            'application_type' => 'auto',
        ]);
    }

    public function test_rejects_payment_exceeding_outstanding_amount_or_from_other_tenant(): void
    {
        [$admin, $payableId] = $this->seedPayableScenario(10, 'ADMIN');
        [$foreignAdmin] = $this->seedPayableScenario(20, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 31,
                'payment_method' => 'card',
                'reference' => 'CARD-999',
            ])
            ->assertStatus(422);

        $this->actingAs($foreignAdmin)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/purchases/payables/{$payableId}")
            ->assertStatus(404);
    }

    public function test_rejects_duplicate_payment_reference_for_same_payable(): void
    {
        [$admin, $payableId] = $this->seedPayableScenario(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 10,
                'payment_method' => 'bank_transfer',
                'reference' => 'TRX-DUP-001',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 5,
                'payment_method' => 'bank_transfer',
                'reference' => 'TRX-DUP-001',
            ])
            ->assertStatus(409);

        $this->assertSame(
            1,
            DB::table('purchase_payments')
                ->where('purchase_payable_id', $payableId)
                ->where('reference', 'TRX-DUP-001')
                ->count()
        );
    }

    public function test_rejects_credit_application_without_available_credit_or_for_other_tenant(): void
    {
        [$admin, $payableId] = $this->seedPayableScenario(10, 'ADMIN');
        [$foreignAdmin, $foreignPayableId] = $this->seedPayableScenario(20, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/apply-credits", [
                'amount' => 5,
            ])
            ->assertStatus(422);

        $this->actingAs($foreignAdmin)
            ->withHeader('X-Tenant-Id', '20')
            ->postJson("/purchases/payables/{$payableId}/apply-credits", [
                'amount' => 5,
            ])
            ->assertStatus(404);

        $this->assertDatabaseMissing('supplier_credit_applications', [
            'purchase_payable_id' => $foreignPayableId,
        ]);
    }

    public function test_admin_can_register_payable_follow_up_and_read_it_from_detail_and_supplier_statement(): void
    {
        [$admin, $payableId, $supplierId] = $this->seedPayableScenario(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/follow-ups", [
                'type' => 'promise',
                'note' => 'Proveedor confirma pago diferido para el lunes',
                'promised_amount' => 15,
                'promised_at' => now()->addDays(4)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'promise')
            ->assertJsonPath('data.promised_amount', 15)
            ->assertJsonPath('data.outstanding_snapshot', 30)
            ->assertJsonPath('data.user.id', $admin->id);

        $this->assertDatabaseHas('purchase_payable_follow_ups', [
            'tenant_id' => 10,
            'purchase_payable_id' => $payableId,
            'type' => 'promise',
            'note' => 'Proveedor confirma pago diferido para el lunes',
            'promised_amount' => 15.00,
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/payables/{$payableId}/follow-ups")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'promise')
            ->assertJsonPath('data.0.note', 'Proveedor confirma pago diferido para el lunes');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/payables/{$payableId}")
            ->assertOk()
            ->assertJsonPath('data.latest_follow_up.type', 'promise')
            ->assertJsonPath('data.latest_follow_up.note', 'Proveedor confirma pago diferido para el lunes');

        $supplierStatementUser = $this->seedUserWithRole(10, 'ALMACENERO');

        $this->actingAs($supplierStatementUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/suppliers/{$supplierId}/statement")
            ->assertOk()
            ->assertJsonPath('data.summary.follow_up_count', 1)
            ->assertJsonPath('data.summary.promised_follow_up_count', 1)
            ->assertJsonPath('data.follow_ups.0.purchase_payable_id', $payableId)
            ->assertJsonPath('data.follow_ups.0.type', 'promise');
    }

    private function seedPayableScenario(int $tenantId, string $roleCode): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => '20'.$tenantId.'1234567',
            'name' => 'Proveedor '.$tenantId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => $user->id,
            'reference' => 'PUR-PAY-'.$tenantId,
            'status' => 'received',
            'total_amount' => 30.00,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payableId = DB::table('purchase_payables')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => 30.00,
            'paid_amount' => 0,
            'outstanding_amount' => 30.00,
            'status' => 'pending',
            'due_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $payableId, $supplierId];
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

    private function seedPurchaseReturn(int $tenantId, int $supplierId, int $userId): int
    {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => $userId,
            'reference' => 'PUR-RET-SEED-'.$tenantId.'-'.$supplierId.'-'.$userId,
            'status' => 'returned',
            'total_amount' => 0,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('purchase_returns')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'purchase_payable_id' => null,
            'user_id' => $userId,
            'reference' => 'PRT-SEED-'.$tenantId.'-'.$supplierId.'-'.$userId.'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => 'processed',
            'reason' => 'Saldo a favor previo',
            'total_amount' => 0,
            'returned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
