<?php

namespace Tests\Feature\Security;

use App\Models\ApiToken;
use App\Http\Middleware\RequirePermission;
use App\Models\User;
use App\Services\Security\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RequirePermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->forgetInstance('currentApiToken');
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

    public function test_denies_bearer_token_when_ability_does_not_cover_required_permission(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->instance('currentApiToken', new ApiToken([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'abilities' => ['reports.daily.read'],
        ]));

        $mock = Mockery::mock(RbacService::class);
        $mock->shouldNotReceive('userHasPermission');
        $this->app->instance(RbacService::class, $mock);

        $request = Request::create('/pos/sale', 'GET');
        $request->attributes->set('tenant_id', 10);
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        $middleware = $this->app->make(RequirePermission::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('API token missing ability: pos.sale.execute');

        $middleware->handle($request, fn () => response('ok'), 'pos.sale.execute');
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

    public function test_allows_bearer_token_when_ability_matches_required_permission(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $user = User::factory()->create();

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->instance('currentApiToken', new ApiToken([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'abilities' => ['pos.sale.execute'],
        ]));

        $mock = Mockery::mock(RbacService::class);
        $mock->shouldReceive('userHasPermission')
            ->once()
            ->andReturnTrue();

        $this->app->instance(RbacService::class, $mock);

        $request = Request::create('/pos/sale', 'GET');
        $request->attributes->set('tenant_id', 10);
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);

        $middleware = $this->app->make(RequirePermission::class);
        $response = $middleware->handle($request, fn () => response('ok'), 'pos.sale.execute');

        $this->assertSame(200, $response->getStatusCode());
    }
}
