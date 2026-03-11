<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_user_can_receive_purchase_and_increase_stock(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20155555555', 'Laboratorios Andinos');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 12,
                    'unit_cost' => 1.75,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.supplier_name', 'Laboratorios Andinos')
            ->assertJsonPath('data.total_amount', 21)
            ->assertJsonPath('data.items.0.resulting_stock', 72);

        $reference = DB::table('purchase_receipts')->where('supplier_id', $supplierId)->value('reference');

        $this->assertDatabaseHas('purchase_receipts', [
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'status' => 'received',
            'total_amount' => 21.00,
        ]);

        $this->assertDatabaseHas('purchase_receipt_items', [
            'lot_id' => $lotId,
            'quantity' => 12,
            'line_total' => 21.00,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => 10,
            'lot_id' => $lotId,
            'type' => 'purchase_in',
            'quantity' => 12,
            'reference' => $reference,
        ]);

        $this->assertDatabaseHas('purchase_payables', [
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => DB::table('purchase_receipts')->where('reference', $reference)->value('id'),
            'total_amount' => 21.00,
            'paid_amount' => 0.00,
            'outstanding_amount' => 21.00,
            'status' => 'pending',
        ]);
    }

    public function test_can_receive_purchase_creating_new_lot_inline(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20156565656', 'Proveedor Nuevo Lote');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'product_id' => $productId,
                    'lot_code' => 'L-PARA-NEW-001',
                    'expires_at' => '2028-12-31',
                    'quantity' => 30,
                    'unit_cost' => 1.20,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.created_new_lot', true)
            ->assertJsonPath('data.items.0.lot_code', 'L-PARA-NEW-001')
            ->assertJsonPath('data.items.0.resulting_stock', 30);

        $this->assertDatabaseHas('lots', [
            'tenant_id' => 10,
            'product_id' => $productId,
            'code' => 'L-PARA-NEW-001',
            'stock_quantity' => 30,
        ]);
    }

    public function test_can_list_purchase_receipts_for_current_tenant_only(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplier10 = $this->seedSupplier(10, '20177777777', 'Proveedor 10');
        $supplier20 = $this->seedSupplier(20, '20288888888', 'Proveedor 20');
        $lot10 = DB::table('lots')->where('tenant_id', 10)->value('id');
        $lot20 = DB::table('lots')->where('tenant_id', 20)->value('id');

        $this->seedReceipt(10, $warehouseUser->id, $supplier10, $lot10, 'PUR-000010');
        $this->seedReceipt(20, User::factory()->create()->id, $supplier20, $lot20, 'PUR-000020');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/receipts')
            ->assertOk()
            ->assertJsonFragment([
                'reference' => 'PUR-000010',
            ])
            ->assertJsonMissing([
                'reference' => 'PUR-000020',
            ]);
    }

    public function test_can_read_purchase_receipt_detail_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20190909090', 'Proveedor Detalle');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'user_id' => $warehouseUser->id,
            'reference' => 'PUR-DETAIL-10',
            'status' => 'received',
            'total_amount' => 14.00,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 4,
            'unit_cost' => 3.50,
            'line_total' => 14.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/receipts/{$receiptId}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'PUR-DETAIL-10')
            ->assertJsonPath('data.supplier.name', 'Proveedor Detalle')
            ->assertJsonPath('data.items.0.product_sku', 'PARA-500')
            ->assertJsonPath('data.items.0.line_total', 14);
    }

    public function test_rejects_purchase_receipt_with_foreign_tenant_supplier(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(20, '20999999999', 'Proveedor 20');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 4,
                    'unit_cost' => 1.50,
                ]],
            ])
            ->assertStatus(404);
    }

    public function test_rejects_purchase_receipt_detail_from_other_tenant(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $foreignUser = $this->seedUserWithRole(20, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20133333333', 'Proveedor 10');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $this->seedReceipt(10, $warehouseUser->id, $supplierId, $lotId, 'PUR-LOCK-010');
        $receiptId = DB::table('purchase_receipts')->where('reference', 'PUR-LOCK-010')->value('id');

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/purchases/receipts/{$receiptId}")
            ->assertStatus(404);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);
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

    private function seedReceipt(int $tenantId, int $userId, int $supplierId, int $lotId, string $reference): void
    {
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => 9.00,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 3,
            'unit_cost' => 3.00,
            'line_total' => 9.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
