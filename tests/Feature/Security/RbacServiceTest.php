<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\RbacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_returns_true_when_permission_exists(): void
    {
        $service = new RbacService();

        $this->assertTrue($service->can(['sales.read', 'sales.write'], 'sales.write'));
    }

    public function test_can_returns_false_when_permission_missing(): void
    {
        $service = new RbacService();

        $this->assertFalse($service->can(['sales.read'], 'sales.approve'));
    }

    public function test_user_has_permission_returns_true_for_assigned_role_in_tenant(): void
    {
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new RbacService();

        $this->assertTrue($service->userHasPermission(10, $user->id, 'rbac.permission.manage'));
    }

    public function test_user_has_permission_returns_false_for_other_tenant(): void
    {
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);

        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new RbacService();

        $this->assertFalse($service->userHasPermission(20, $user->id, 'rbac.permission.manage'));
    }
}
