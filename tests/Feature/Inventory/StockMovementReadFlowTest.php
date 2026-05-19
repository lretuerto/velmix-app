<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_stock_movements_for_current_tenant_only(): void
    {
        $this->seedCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $tenant10LotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $tenant10ProductId = DB::table('lots')->where('id', $tenant10LotId)->value('product_id');
        $tenant20LotId = DB::table('lots')->where('tenant_id', 20)->value('id');
        $tenant20ProductId = DB::table('lots')->where('id', $tenant20LotId)->value('product_id');

        DB::table('stock_movements')->insert([
            [
                'tenant_id' => 10,
                'lot_id' => $tenant10LotId,
                'product_id' => $tenant10ProductId,
                'sale_id' => null,
                'type' => 'manual_in',
                'quantity' => 8,
                'reference' => 'AJUSTE-ENTRADA-10',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 20,
                'lot_id' => $tenant20LotId,
                'product_id' => $tenant20ProductId,
                'sale_id' => null,
                'type' => 'manual_out',
                'quantity' => -2,
                'reference' => 'AJUSTE-SALIDA-20',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/inventory/movements')
            ->assertOk()
            ->assertJsonFragment([
                'type' => 'manual_in',
                'reference' => 'AJUSTE-ENTRADA-10',
            ])
            ->assertJsonMissing([
                'reference' => 'AJUSTE-SALIDA-20',
            ]);
    }

    public function test_filters_stock_movements_by_type_for_current_tenant(): void
    {
        $this->seedCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/stock/movements', [
                'lot_id' => $lotId,
                'type' => 'manual_in',
                'quantity' => 5,
                'reference' => 'ENTRADA-REPORTE',
            ])
            ->assertOk();

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/stock/movements', [
                'lot_id' => $lotId,
                'type' => 'manual_out',
                'quantity' => 2,
                'reference' => 'SALIDA-REPORTE',
            ])
            ->assertOk();

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/inventory/movements?type=manual_out')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'manual_out')
            ->assertJsonPath('data.0.reference', 'SALIDA-REPORTE');
    }

    private function seedCatalog(): void
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
