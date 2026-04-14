<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_request_without_tenant_header(): void
    {
        $user = $this->seedTenantUser(10, withRole: true);

        $this->actingAs($user)
            ->getJson('/tenant/ping')
            ->assertStatus(400)
            ->assertJson(['message' => 'Tenant context is required']);
    }

    public function test_rejects_unauthenticated_request_even_with_tenant_header(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $this->withHeaders(['X-Tenant-Id' => '10'])
            ->getJson('/tenant/ping')
            ->assertStatus(401);
    }

    public function test_allows_authenticated_request_with_tenant_header(): void
    {
        $user = $this->seedTenantUser(10, withRole: true);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-Id' => '10'])
            ->getJson('/tenant/ping')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'tenant' => '10',
                'auth_mode' => 'session',
            ]);
    }

    private function seedTenantUser(int $tenantId, bool $withRole = false): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withRole) {
            $roleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

            DB::table('tenant_user_role')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
