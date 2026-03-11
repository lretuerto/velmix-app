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

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 5,
                'unit_price' => 3.50,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.allocations.0.remaining_stock', 55)
            ->assertJsonPath('data.total_amount', 17.5);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'status' => 'completed',
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
}
