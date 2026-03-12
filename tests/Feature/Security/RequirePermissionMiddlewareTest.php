<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RequirePermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_denies_when_permission_is_missing(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = Mockery::mock(RbacService::class);
        $mock->shouldReceive('userHasPermission')
            ->once()
            ->andReturnFalse();

        $this->app->instance(RbacService::class, $mock);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/pos/sale');

        $response->assertStatus(403);
    }

    public function test_allows_when_permission_exists(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = Mockery::mock(RbacService::class);
        $mock->shouldReceive('userHasPermission')
            ->once()
            ->andReturnTrue();

        $this->app->instance(RbacService::class, $mock);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->get('/pos/sale');

        $response->assertOk();
    }
}
