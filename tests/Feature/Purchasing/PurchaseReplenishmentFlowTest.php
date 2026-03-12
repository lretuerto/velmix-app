<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReplenishmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_replenishment_suggestions_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $paracetamolId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $foreignProductId = DB::table('products')->where('tenant_id', 20)->where('sku', 'AMOX-500')->value('id');

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'IBU-400',
            'name' => 'Ibuprofeno 400mg',
            'status' => 'active',
            'is_controlled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ibuprofenId = DB::table('products')->where('tenant_id', 10)->where('sku', 'IBU-400')->value('id');

        DB::table('lots')->insert([
            [
                'tenant_id' => 10,
                'product_id' => $ibuprofenId,
                'code' => 'L-IBU-001',
                'expires_at' => now()->addDays(10)->toDateString(),
                'stock_quantity' => 8,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 20,
                'product_id' => $foreignProductId,
                'code' => 'L-AMOX-999',
                'expires_at' => now()->addDays(10)->toDateString(),
                'stock_quantity' => 1,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('stock_movements')->insert([
            [
                'tenant_id' => 10,
                'lot_id' => DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id'),
                'product_id' => $paracetamolId,
                'sale_id' => null,
                'type' => 'sale',
                'quantity' => -45,
                'reference' => 'SALE-LOOKBACK-1',
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
            [
                'tenant_id' => 10,
                'lot_id' => DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-002')->value('id'),
                'product_id' => $paracetamolId,
                'sale_id' => null,
                'type' => 'sale',
                'quantity' => -30,
                'reference' => 'SALE-LOOKBACK-2',
                'created_at' => now()->subDays(10),
                'updated_at' => now()->subDays(10),
            ],
            [
                'tenant_id' => 20,
                'lot_id' => DB::table('lots')->where('tenant_id', 20)->value('id'),
                'product_id' => $foreignProductId,
                'sale_id' => null,
                'type' => 'sale',
                'quantity' => -200,
                'reference' => 'SALE-FOREIGN',
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ],
        ]);

        DB::table('lots')
            ->where('tenant_id', 10)
            ->where('code', 'L-PARA-001')
            ->update([
                'expires_at' => now()->addDays(5)->toDateString(),
                'stock_quantity' => 25,
                'updated_at' => now(),
            ]);

        DB::table('lots')
            ->where('tenant_id', 10)
            ->where('code', 'L-PARA-002')
            ->update([
                'stock_quantity' => 15,
                'updated_at' => now(),
            ]);

        $response = $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/replenishment-suggestions?lookback_days=15&coverage_days=10&expiring_within_days=15&low_stock_threshold=20');

        $response->assertOk()
            ->assertJsonPath('tenant_id', 10)
            ->assertJsonPath('parameters.lookback_days', 15)
            ->assertJsonPath('data.0.sku', 'PARA-500')
            ->assertJsonPath('data.0.current_stock', 40)
            ->assertJsonPath('data.0.expiring_soon_stock', 25)
            ->assertJsonPath('data.0.usable_stock', 15)
            ->assertJsonPath('data.0.recent_sales_quantity', 75)
            ->assertJsonPath('data.0.projected_demand', 50)
            ->assertJsonPath('data.0.suggested_order_quantity', 35)
            ->assertJsonPath('data.1.sku', 'IBU-400')
            ->assertJsonPath('data.1.reason', 'expiring_stock')
            ->assertJsonMissing([
                'sku' => 'AMOX-500',
            ]);
    }

    public function test_cashier_cannot_read_replenishment_suggestions(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/replenishment-suggestions')
            ->assertStatus(403);
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
}
