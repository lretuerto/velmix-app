<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LotControlFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_immobilize_lot_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

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
            ->postJson("/inventory/lots/{$lotId}/immobilize", [
                'reason' => 'Inmovilizacion sanitaria preventiva',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'immobilized');

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'status' => 'immobilized',
        ]);
    }
}
