<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantTeamManagementFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_team_user_list_roles_and_sync_roles(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $createResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/users', [
                'name' => 'Operador Caja',
                'email' => 'caja@velmix.test',
                'password' => 'secret123',
                'roles' => ['CAJERO'],
            ]);

        $createResponse->assertOk()
            ->assertJsonPath('data.email', 'caja@velmix.test')
            ->assertJsonPath('data.roles.0.code', 'CAJERO');

        $userId = (int) $createResponse->json('data.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/admin/team/roles')
            ->assertOk()
            ->assertJsonFragment(['code' => 'ADMIN'])
            ->assertJsonFragment(['code' => 'CAJERO']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson(sprintf('/admin/team/users/%d/roles', $userId), [
                'roles' => ['ALMACENERO'],
            ])
            ->assertOk()
            ->assertJsonFragment(['code' => 'ALMACENERO'])
            ->assertJsonMissing(['code' => 'CAJERO']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/admin/team/users')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $userId,
                'email' => 'caja@velmix.test',
            ]);

        $warehouseRoleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => 10,
            'user_id' => $userId,
        ]);

        $this->assertDatabaseHas('tenant_user_role', [
            'tenant_id' => 10,
            'user_id' => $userId,
            'role_id' => $warehouseRoleId,
        ]);
    }

    public function test_cashier_cannot_manage_team_bootstrap_endpoints(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/admin/team/users')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/users', [
                'name' => 'No permitido',
                'email' => 'forbidden@velmix.test',
                'password' => 'secret123',
            ])
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
