<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_purchase_order_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $supplierId = $this->seedSupplier(10, '20112312312', 'Proveedor OC');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/orders', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'product_id' => $productId,
                    'ordered_quantity' => 25,
                    'unit_cost' => 1.90,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.total_amount', 47.5)
            ->assertJsonPath('data.items.0.received_quantity', 0);

        $this->assertDatabaseHas('purchase_orders', [
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'status' => 'open',
            'total_amount' => 47.50,
        ]);
    }

    public function test_can_list_and_detail_purchase_orders_for_current_tenant_only(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplier10 = $this->seedSupplier(10, '20122222222', 'Proveedor 10');
        $supplier20 = $this->seedSupplier(20, '20233333333', 'Proveedor 20');
        $product10 = DB::table('products')->where('tenant_id', 10)->value('id');
        $product20 = DB::table('products')->where('tenant_id', 20)->value('id');

        $order10 = $this->seedPurchaseOrder(10, $warehouseUser->id, $supplier10, $product10, 'PO-000010');
        $this->seedPurchaseOrder(20, User::factory()->create()->id, $supplier20, $product20, 'PO-000020');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/orders')
            ->assertOk()
            ->assertJsonFragment([
                'reference' => 'PO-000010',
            ])
            ->assertJsonMissing([
                'reference' => 'PO-000020',
            ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/orders/{$order10}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'PO-000010')
            ->assertJsonPath('data.items.0.ordered_quantity', 10);
    }

    public function test_receipt_updates_purchase_order_to_partially_received_then_received(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20145454545', 'Proveedor Progreso');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $createResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/orders', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'product_id' => $productId,
                    'ordered_quantity' => 10,
                    'unit_cost' => 2.10,
                ]],
            ])
            ->assertOk();

        $orderId = $createResponse->json('data.id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'purchase_order_id' => $orderId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 4,
                    'unit_cost' => 2.10,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.purchase_order_id', $orderId);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $orderId,
            'status' => 'partially_received',
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'product_id' => $productId,
            'received_quantity' => 4,
        ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'purchase_order_id' => $orderId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 6,
                    'unit_cost' => 2.10,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $orderId,
            'status' => 'received',
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'product_id' => $productId,
            'received_quantity' => 10,
        ]);
    }

    public function test_rejects_receipt_when_quantity_exceeds_purchase_order(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20198989898', 'Proveedor Limite');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $createResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/orders', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'product_id' => $productId,
                    'ordered_quantity' => 5,
                    'unit_cost' => 2.00,
                ]],
            ])
            ->assertOk();

        $orderId = $createResponse->json('data.id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'purchase_order_id' => $orderId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 6,
                    'unit_cost' => 2.00,
                ]],
            ])
            ->assertStatus(422);
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

    private function seedPurchaseOrder(int $tenantId, int $userId, int $supplierId, int $productId, string $reference): int
    {
        $orderId = DB::table('purchase_orders')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'open',
            'total_amount' => 15.00,
            'ordered_at' => now(),
            'received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $orderId,
            'product_id' => $productId,
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 1.50,
            'line_total' => 15.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
