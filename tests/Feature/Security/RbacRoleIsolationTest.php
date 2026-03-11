<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cajero_no_puede_aprobar_operacion_critica(): void
    {
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/pos/approve')
            ->assertStatus(403);
    }

    public function test_admin_puede_gestionar_permisos_no_criticos(): void
    {
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);

        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/rbac/permissions')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'flow' => 'rbac-permissions',
            ]);
    }
}
