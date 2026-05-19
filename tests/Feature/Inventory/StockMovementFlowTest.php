<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockMovementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_manual_stock_entry_for_tenant_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
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
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/stock/movements', [
                'lot_id' => $lotId,
                'type' => 'manual_in',
                'quantity' => 30,
                'reference' => 'ADJ-IN-001',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'manual_in')
            ->assertJsonPath('data.quantity', 30)
            ->assertJsonPath('data.resulting_stock', 90);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => 10,
            'lot_id' => $lotId,
            'type' => 'manual_in',
            'quantity' => 30,
            'reference' => 'ADJ-IN-001',
        ]);
    }

    public function test_rejects_manual_stock_exit_when_result_would_be_negative(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
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
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/stock/movements', [
                'lot_id' => $lotId,
                'type' => 'manual_out',
                'quantity' => 999,
                'reference' => 'ADJ-OUT-001',
            ])
            ->assertStatus(422);
    }

    public function test_rejects_stock_movement_for_foreign_tenant_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
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
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/stock/movements', [
                'lot_id' => $foreignLotId,
                'type' => 'manual_in',
                'quantity' => 10,
                'reference' => 'ADJ-IN-002',
            ])
            ->assertStatus(404);
    }
}
