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
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.remaining_stock', 115)
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
            'stock_quantity' => 115,
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
}
