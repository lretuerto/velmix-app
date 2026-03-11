<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_no_puede_ver_datos_de_otro_tenant(): void
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
            ->withHeader('X-Tenant-Id', '20')
            ->get('/rbac/permissions')
            ->assertStatus(403);
    }

    public function test_query_de_negocio_requiere_contexto_tenant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/pos/sale')
            ->assertStatus(400)
            ->assertJson([
                'message' => 'Tenant context is required',
            ]);
    }
}
