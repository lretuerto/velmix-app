<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiTokenAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_admin_can_create_and_list_api_tokens_for_current_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);

        $createResponse = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/auth/tokens', [
                'name' => 'Integracion POS',
                'abilities' => ['sales.read', 'sales.write'],
                'expires_at' => now()->addDays(30)->toDateString(),
            ]);

        $createResponse->assertOk()
            ->assertJsonPath('data.name', 'Integracion POS')
            ->assertJsonPath('data.token_type', 'Bearer');

        $plainTextToken = $createResponse->json('data.plain_text_token');
        $this->assertNotEmpty($plainTextToken);

        $this->assertDatabaseHas('api_tokens', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'name' => 'Integracion POS',
            'token_prefix' => substr($plainTextToken, 0, 12),
        ]);

        $tokenId = DB::table('api_tokens')
            ->where('tenant_id', 10)
            ->where('user_id', $user->id)
            ->where('name', 'Integracion POS')
            ->value('id');

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'domain' => 'security',
            'event_type' => 'security.api_token.created',
            'aggregate_type' => 'api_token',
            'aggregate_id' => $tokenId,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/auth/tokens')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Integracion POS')
            ->assertJsonPath('data.0.plain_text_token', null);
    }

    public function test_bearer_token_can_access_protected_route_for_its_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);
        $plainTextToken = $this->createTokenForUser($user, 10, 'API App');

        $this->withToken($plainTextToken)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.auth_mode', 'bearer');

        $this->assertDatabaseMissing('api_tokens', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'name' => 'API App',
            'last_used_at' => null,
        ]);
    }

    public function test_revoked_token_cannot_access_protected_route(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);
        $createResponse = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/auth/tokens', [
                'name' => 'Temporal',
            ]);

        $createResponse->assertOk();
        $tokenId = (int) $createResponse->json('data.id');
        $plainTextToken = (string) $createResponse->json('data.plain_text_token');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->deleteJson(sprintf('/auth/tokens/%d', $tokenId))
            ->assertOk()
            ->assertJsonPath('data.id', $tokenId);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'domain' => 'security',
            'event_type' => 'security.api_token.revoked',
            'aggregate_type' => 'api_token',
            'aggregate_id' => $tokenId,
        ]);

        $this->withToken($plainTextToken)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/auth/me')
            ->assertStatus(401);
    }

    public function test_token_is_scoped_to_its_tenant_even_if_user_has_other_memberships(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);

        DB::table('tenant_user')->insert([
            'tenant_id' => 20,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plainTextToken = $this->createTokenForUser($user, 10, 'Tenant 10 only');

        $this->withToken($plainTextToken)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson('/auth/me')
            ->assertStatus(403);
    }

    public function test_bearer_token_is_limited_by_declared_abilities_on_permissioned_routes(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantAdminUser(10);
        $plainTextToken = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/auth/tokens', [
                'name' => 'Reporte Diario',
                'abilities' => [' reports.daily.read ', 'reports.daily.read'],
            ])
            ->assertOk()
            ->assertJsonPath('data.abilities.0', 'reports.daily.read')
            ->json('data.plain_text_token');

        $this->withToken((string) $plainTextToken)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/daily?date=2026-03-12')
            ->assertOk();

        $this->withToken((string) $plainTextToken)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/pos/sale')
            ->assertStatus(403);

        $this->assertDatabaseHas('api_tokens', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'name' => 'Reporte Diario',
        ]);
    }

    public function test_non_admin_tenant_member_cannot_manage_api_tokens(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedTenantUser(10);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/auth/tokens')
            ->assertStatus(403);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/auth/tokens', [
                'name' => 'No permitido',
            ])
            ->assertStatus(403);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->deleteJson('/auth/tokens/999')
            ->assertStatus(403);
    }

    private function createTokenForUser(User $user, int $tenantId, string $name): string
    {
        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', (string) $tenantId)
            ->postJson('/auth/tokens', [
                'name' => $name,
            ]);

        $response->assertOk();

        return (string) $response->json('data.plain_text_token');
    }

    private function seedTenantUser(int $tenantId): User
    {
        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function seedTenantAdminUser(int $tenantId): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

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
