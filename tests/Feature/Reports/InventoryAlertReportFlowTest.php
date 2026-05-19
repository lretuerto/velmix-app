<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryAlertReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_inventory_alerts_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'IBU-200',
            'name' => 'Ibuprofeno 200mg',
            'status' => 'active',
            'is_controlled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'IBU-200')->value('id');
        $foreignProductId = DB::table('products')->where('tenant_id', 20)->where('sku', 'AMOX-500')->value('id');
        $immobilizedLotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-002')->value('id');

        DB::table('lots')->insert([
            [
                'tenant_id' => 10,
                'product_id' => $productId,
                'code' => 'L-IBU-001',
                'expires_at' => now()->addDays(5)->toDateString(),
                'stock_quantity' => 4,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 20,
                'product_id' => $foreignProductId,
                'code' => 'L-AMOX-EXP',
                'expires_at' => now()->addDays(3)->toDateString(),
                'stock_quantity' => 2,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('lots')->where('id', $immobilizedLotId)->update([
            'status' => 'immobilized',
            'updated_at' => now(),
        ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/inventory-alerts?low_stock_threshold=5&expiring_within_days=7')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.thresholds.low_stock_threshold', 5)
            ->assertJsonPath('data.thresholds.expiring_within_days', 7)
            ->assertJsonPath('data.summary.low_stock_products_count', 1)
            ->assertJsonPath('data.summary.expiring_lots_count', 1)
            ->assertJsonPath('data.summary.immobilized_lots_count', 1)
            ->assertJsonPath('data.low_stock_products.0.sku', 'IBU-200')
            ->assertJsonPath('data.expiring_lots.0.code', 'L-IBU-001')
            ->assertJsonPath('data.immobilized_lots.0.lot_id', $immobilizedLotId)
            ->assertJsonMissing([
                'code' => 'L-AMOX-EXP',
            ]);
    }

    public function test_cashier_cannot_read_inventory_alerts_without_permission(): void
    {
        $this->seedBaseCatalog();
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/inventory-alerts')
            ->assertStatus(403);
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
}
