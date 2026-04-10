<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventorySetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_product_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/inventory/products', [
                'sku' => 'IBUP-400',
                'name' => 'Ibuprofeno 400mg',
                'is_controlled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.sku', 'IBUP-400');

        $this->assertDatabaseHas('products', [
            'tenant_id' => 10,
            'sku' => 'IBUP-400',
        ]);
    }

    public function test_rejects_duplicate_product_sku_in_same_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/inventory/products', [
                'sku' => 'PARA-500',
                'name' => 'Paracetamol duplicado',
                'is_controlled' => false,
            ])
            ->assertStatus(422);
    }

    public function test_creates_lot_for_product_in_same_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/inventory/lots', [
                'product_id' => $productId,
                'code' => 'L-PARA-003',
                'expires_at' => '2028-01-31',
                'stock_quantity' => 80,
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'L-PARA-003');

        $this->assertDatabaseHas('lots', [
            'tenant_id' => 10,
            'code' => 'L-PARA-003',
            'stock_quantity' => 80,
        ]);
    }

    public function test_rejects_lot_creation_for_foreign_tenant_product(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
        $foreignProductId = DB::table('products')->where('tenant_id', 20)->where('sku', 'AMOX-500')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/inventory/lots', [
                'product_id' => $foreignProductId,
                'code' => 'L-BLOCK-001',
                'expires_at' => '2028-01-31',
                'stock_quantity' => 50,
            ])
            ->assertStatus(404);
    }
}
