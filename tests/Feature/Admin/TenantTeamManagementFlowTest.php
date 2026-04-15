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

    public function test_admin_cannot_attach_existing_user_from_other_tenant_or_mutate_global_identity(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $existing = User::factory()->create([
            'name' => 'Identidad Compartida',
            'email' => 'shared@velmix.test',
        ]);

        DB::table('tenant_user')->insert([
            'tenant_id' => 20,
            'user_id' => $existing->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/users', [
                'name' => 'Nombre Intentado',
                'email' => 'shared@velmix.test',
                'roles' => ['CAJERO'],
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => 10,
            'user_id' => $existing->id,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'name' => 'Identidad Compartida',
            'email' => 'shared@velmix.test',
        ]);
    }

    public function test_bootstrap_does_not_rename_existing_user_in_same_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $existing = User::factory()->create([
            'name' => 'Nombre Original',
            'email' => 'same-tenant@velmix.test',
        ]);

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $existing->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/users', [
                'name' => 'Nombre Nuevo',
                'email' => 'same-tenant@velmix.test',
                'roles' => ['CAJERO'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nombre Original')
            ->assertJsonPath('data.roles.0.code', 'CAJERO');

        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'name' => 'Nombre Original',
            'email' => 'same-tenant@velmix.test',
        ]);
    }

    public function test_admin_can_create_list_and_revoke_team_invitation(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $createResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'invitee@velmix.test',
                'name' => 'Invitado Operativo',
                'roles' => ['CAJERO'],
                'expires_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.email', 'invitee@velmix.test')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role_codes.0', 'CAJERO');

        $invitationId = (int) $createResponse->json('data.id');
        $this->assertNotEmpty($createResponse->json('data.plain_text_token'));

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/admin/team/invitations')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $invitationId,
                'email' => 'invitee@velmix.test',
                'status' => 'pending',
            ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/admin/team/invitations/{$invitationId}/revoke", [
                'reason' => 'Cambio de plan operativo',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.revoke_reason', 'Cambio de plan operativo');

        $this->assertDatabaseHas('tenant_user_invitations', [
            'id' => $invitationId,
            'tenant_id' => 10,
            'email' => 'invitee@velmix.test',
            'status' => 'revoked',
            'pending_guard' => null,
        ]);
    }

    public function test_new_user_can_accept_team_invitation_and_join_tenant_with_roles(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $inviteResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'new-member@velmix.test',
                'name' => 'Nuevo Miembro',
                'roles' => ['ALMACENERO'],
            ])
            ->assertOk();

        $token = (string) $inviteResponse->json('data.plain_text_token');

        $acceptResponse = $this->postJson('/team/invitations/accept', [
            'token' => $token,
            'name' => 'Nuevo Miembro',
            'password' => 'secret123',
        ]);

        $acceptResponse->assertOk()
            ->assertJsonPath('data.invitation.status', 'accepted')
            ->assertJsonPath('data.user.email', 'new-member@velmix.test')
            ->assertJsonPath('data.user.roles.0.code', 'ALMACENERO');

        $userId = (int) $acceptResponse->json('data.user.id');
        $warehouseRoleId = DB::table('roles')->where('code', 'ALMACENERO')->value('id');

        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'new-member@velmix.test',
            'name' => 'Nuevo Miembro',
        ]);

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

    public function test_existing_user_from_other_tenant_can_accept_invitation_only_with_matching_session(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $existing = User::factory()->create([
            'name' => 'Usuario Compartido',
            'email' => 'shared-invite@velmix.test',
        ]);

        DB::table('tenant_user')->insert([
            'tenant_id' => 20,
            'user_id' => $existing->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inviteResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'shared-invite@velmix.test',
                'roles' => ['CAJERO'],
            ])
            ->assertOk();

        $token = (string) $inviteResponse->json('data.plain_text_token');

        $this->postJson('/team/invitations/accept', [
            'token' => $token,
        ])
            ->assertStatus(409);

        $otherUser = User::factory()->create([
            'email' => 'otro@velmix.test',
        ]);

        $this->actingAs($otherUser)
            ->postJson('/team/invitations/accept', [
                'token' => $token,
            ])
            ->assertStatus(409);

        $acceptResponse = $this->actingAs($existing)
            ->postJson('/team/invitations/accept', [
                'token' => $token,
            ]);

        $acceptResponse->assertOk()
            ->assertJsonPath('data.invitation.status', 'accepted')
            ->assertJsonPath('data.user.id', $existing->id)
            ->assertJsonPath('data.user.name', 'Usuario Compartido')
            ->assertJsonPath('data.user.email', 'shared-invite@velmix.test')
            ->assertJsonPath('data.user.roles.0.code', 'CAJERO');

        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => 10,
            'user_id' => $existing->id,
        ]);

        $this->assertDatabaseHas('tenant_user_role', [
            'tenant_id' => 10,
            'user_id' => $existing->id,
            'role_id' => $cashierRoleId,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'name' => 'Usuario Compartido',
            'email' => 'shared-invite@velmix.test',
        ]);
    }

    public function test_duplicate_pending_invitation_is_rejected_and_cashier_cannot_manage_it(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'dup@velmix.test',
                'roles' => ['CAJERO'],
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'dup@velmix.test',
                'roles' => ['CAJERO'],
            ])
            ->assertStatus(409);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/admin/team/invitations')
            ->assertStatus(403);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/admin/team/invitations', [
                'email' => 'blocked@velmix.test',
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
