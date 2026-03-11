<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_sale_and_decrements_lot_stock(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        DB::table('products')->where('id', $productId)->update([
            'average_cost' => 1.20,
            'last_cost' => 1.20,
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 5,
                'unit_price' => 3.50,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.allocations.0.remaining_stock', 55)
            ->assertJsonPath('data.total_amount', 17.5)
            ->assertJsonPath('data.gross_cost', 6)
            ->assertJsonPath('data.gross_margin', 11.5);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 17.50,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => 10,
            'lot_id' => $lotId,
            'type' => 'sale',
            'quantity' => -5,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 55,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'lot_id' => $lotId,
            'unit_cost_snapshot' => 1.20,
            'cost_amount' => 6.00,
            'gross_margin' => 11.50,
        ]);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'gross_cost' => 6.00,
            'gross_margin' => 11.50,
        ]);
    }

    public function test_rejects_sale_when_lot_belongs_to_another_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $foreignLotId = DB::table('lots')->where('tenant_id', 20)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $foreignLotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(404);
    }

    public function test_rejects_sale_when_stock_is_insufficient(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 999,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    public function test_executes_fifo_sale_for_product_across_multiple_lots(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $firstLotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $secondLotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-002')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 70,
                        'unit_price' => 3.50,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.lot_id', $firstLotId)
            ->assertJsonPath('data.items.0.allocations.0.quantity', 60)
            ->assertJsonPath('data.items.0.allocations.1.lot_id', $secondLotId)
            ->assertJsonPath('data.items.0.allocations.1.quantity', 10)
            ->assertJsonPath('data.total_amount', 245);

        $this->assertDatabaseHas('lots', [
            'id' => $firstLotId,
            'stock_quantity' => 0,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $secondLotId,
            'stock_quantity' => 50,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $firstLotId,
            'quantity' => -60,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $secondLotId,
            'quantity' => -10,
        ]);
    }

    public function test_executes_multi_item_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'LORA-10',
            'name' => 'Loratadina 10mg',
            'status' => 'active',
            'is_controlled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondProductId = DB::table('products')->where('tenant_id', 10)->where('sku', 'LORA-10')->value('id');

        DB::table('lots')->insert([
            'tenant_id' => 10,
            'product_id' => $secondProductId,
            'code' => 'L-LORA-001',
            'expires_at' => '2027-11-30',
            'stock_quantity' => 40,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $paracetamolProductId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'product_id' => $paracetamolProductId,
                        'quantity' => 5,
                        'unit_price' => 3.50,
                    ],
                    [
                        'product_id' => $secondProductId,
                        'quantity' => 2,
                        'unit_price' => 4.20,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.total_amount', 25.9);
    }

    public function test_persists_non_cash_payment_method_for_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 2,
                'unit_price' => 3.50,
                'payment_method' => 'card',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'card');

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'payment_method' => 'card',
        ]);
    }

    public function test_rejects_controlled_product_sale_without_prescription_or_approval(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_allows_controlled_product_sale_with_prescription_code(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                    'prescription_code' => 'RX-001',
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $controlledProductId,
            'prescription_code' => 'RX-001',
        ]);
    }

    public function test_allows_controlled_product_sale_with_admin_approval_and_consumes_it(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();
        $admin = $this->seedAdminUser();

        $approvalResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/approvals', [
                'product_id' => $controlledProductId,
                'reason' => 'Urgencia validada por quimico farmaceutico',
            ]);

        $approvalResponse->assertOk();
        $approvalCode = $approvalResponse->json('data.code');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                    'approval_code' => $approvalCode,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $controlledProductId,
            'approval_code' => $approvalCode,
        ]);

        $this->assertDatabaseHas('sale_approvals', [
            'code' => $approvalCode,
            'status' => 'consumed',
        ]);
    }

    public function test_rejects_sale_for_immobilized_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'status' => 'immobilized',
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    public function test_rejects_sale_for_expired_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'expires_at' => now()->subDay()->toDateString(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    private function seedControlledProduct(): int
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'CLON-2',
            'name' => 'Clonazepam 2mg',
            'status' => 'active',
            'is_controlled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'CLON-2')->value('id');

        DB::table('lots')->insert([
            'tenant_id' => 10,
            'product_id' => $productId,
            'code' => 'L-CLON-001',
            'expires_at' => '2027-09-30',
            'stock_quantity' => 25,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function seedCashierUser(): User
    {
        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function seedAdminUser(): User
    {
        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
