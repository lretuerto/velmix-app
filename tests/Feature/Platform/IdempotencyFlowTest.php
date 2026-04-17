<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_replays_same_voucher_response_for_same_idempotency_key_and_blocks_payload_drift(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedTenantAdminUser(10);
        $saleA = $this->seedCompletedSale(10, 'SALE-IDEMP-A');
        $saleB = $this->seedCompletedSale(10, 'SALE-IDEMP-B');

        $first = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleA,
                'type' => 'boleta',
            ]);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $voucherId = (int) $first->json('data.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleA,
                'type' => 'boleta',
            ])
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $voucherId);

        $this->assertSame(1, DB::table('electronic_vouchers')->where('sale_id', $saleA)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_type', 'electronic_voucher')->where('aggregate_id', $voucherId)->count());

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'voucher-issue-001')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleB,
                'type' => 'boleta',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Idempotency key already exists for a different request payload.');
    }

    public function test_replays_same_purchase_order_creation_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedTenantUserWithRole(10, 'ADMIN');
        $supplierId = $this->seedSupplier(10, '20170000001', 'Proveedor Idempotente');
        $productId = (int) DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        $payload = [
            'supplier_id' => $supplierId,
            'items' => [[
                'product_id' => $productId,
                'ordered_quantity' => 12,
                'unit_cost' => 2.25,
            ]],
        ];

        $first = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-order-001')
            ->postJson('/purchases/orders', $payload);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $orderId = (int) $first->json('data.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-order-001')
            ->postJson('/purchases/orders', $payload)
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $orderId);

        $this->assertSame(1, DB::table('purchase_orders')->where('tenant_id', 10)->count());
        $this->assertSame(1, DB::table('purchase_order_items')->where('purchase_order_id', $orderId)->count());
    }

    public function test_replays_same_inventory_product_creation_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $warehouse = $this->seedTenantUserWithRole(10, 'ALMACENERO');

        $payload = [
            'sku' => 'DICLO-050',
            'name' => 'Diclofenaco 50mg',
            'is_controlled' => false,
        ];

        $first = $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'inventory-product-001')
            ->postJson('/inventory/products', $payload);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $productId = (int) $first->json('data.id');

        $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'inventory-product-001')
            ->postJson('/inventory/products', $payload)
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $productId);

        $this->assertSame(1, DB::table('products')->where('tenant_id', 10)->where('sku', 'DICLO-050')->count());
    }

    public function test_replays_same_inventory_lot_creation_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $warehouse = $this->seedTenantUserWithRole(10, 'ALMACENERO');
        $productId = (int) DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        $payload = [
            'product_id' => $productId,
            'code' => 'L-PARA-777',
            'expires_at' => '2029-06-30',
            'stock_quantity' => 45,
        ];

        $first = $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'inventory-lot-001')
            ->postJson('/inventory/lots', $payload);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $lotId = (int) $first->json('data.id');

        $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'inventory-lot-001')
            ->postJson('/inventory/lots', $payload)
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $lotId);

        $this->assertSame(1, DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-777')->count());
    }

    public function test_replays_same_purchase_receipt_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $warehouse = $this->seedTenantUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20170000002', 'Proveedor Recepcion Idempotente');
        $lotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $initialStock = (int) DB::table('lots')->where('id', $lotId)->value('stock_quantity');

        $payload = [
            'supplier_id' => $supplierId,
            'items' => [[
                'lot_id' => $lotId,
                'quantity' => 7,
                'unit_cost' => 1.80,
            ]],
        ];

        $first = $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-receipt-001')
            ->postJson('/purchases/receipts', $payload);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $receiptId = (int) $first->json('data.id');

        $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-receipt-001')
            ->postJson('/purchases/receipts', $payload)
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $receiptId);

        $this->assertSame(1, DB::table('purchase_receipts')->where('tenant_id', 10)->where('supplier_id', $supplierId)->count());
        $this->assertSame(1, DB::table('purchase_payables')->where('purchase_receipt_id', $receiptId)->count());
        $this->assertSame($initialStock + 7, (int) DB::table('lots')->where('id', $lotId)->value('stock_quantity'));
    }

    public function test_replays_same_purchase_return_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $warehouse = $this->seedTenantUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20170000003', 'Proveedor Return Idempotente');
        $lotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $receiptResponse = $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 8,
                    'unit_cost' => 2.00,
                ]],
            ])
            ->assertOk();

        $receiptId = (int) $receiptResponse->json('data.id');
        $receiptItemId = (int) DB::table('purchase_receipt_items')->where('purchase_receipt_id', $receiptId)->value('id');
        $stockBeforeReturn = (int) DB::table('lots')->where('id', $lotId)->value('stock_quantity');

        $payload = [
            'reason' => 'Idempotent return',
            'items' => [[
                'purchase_receipt_item_id' => $receiptItemId,
                'quantity' => 3,
            ]],
        ];

        $first = $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-return-001')
            ->postJson("/purchases/receipts/{$receiptId}/returns", $payload);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored');

        $returnId = (int) $first->json('data.id');

        $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'purchase-return-001')
            ->postJson("/purchases/receipts/{$receiptId}/returns", $payload)
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.id', $returnId);

        $this->assertSame(1, DB::table('purchase_returns')->where('purchase_receipt_id', $receiptId)->count());
        $this->assertSame($stockBeforeReturn - 3, (int) DB::table('lots')->where('id', $lotId)->value('stock_quantity'));
    }

    public function test_replays_same_sale_cancellation_for_same_idempotency_key(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $admin = $this->seedTenantUserWithRole(10, 'ADMIN');
        $cashier = $this->seedTenantUserWithRole(10, 'CAJERO');
        $lotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $saleResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 4,
                'unit_price' => 3.50,
            ])
            ->assertOk();

        $saleId = (int) $saleResponse->json('data.sale_id');

        $first = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'sale-cancel-001')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Cancelacion idempotente',
            ]);

        $first->assertOk()
            ->assertHeader('X-Idempotency-Status', 'stored')
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'sale-cancel-001')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Cancelacion idempotente',
            ])
            ->assertOk()
            ->assertHeader('X-Idempotency-Status', 'replayed')
            ->assertJsonPath('data.sale_id', $saleId);

        $this->assertSame(1, DB::table('stock_movements')->where('sale_id', $saleId)->where('type', 'sale_reversal')->count());
    }

    private function seedCompletedSale(int $tenantId, string $reference): int
    {
        return (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 25,
            'gross_cost' => 10,
            'gross_margin' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedTenantAdminUser(int $tenantId): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

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

    private function seedTenantUserWithRole(int $tenantId, string $roleCode): User
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
        return (int) DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
