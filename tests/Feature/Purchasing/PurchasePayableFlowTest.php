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

        return [$user, $payableId];
    }
}
