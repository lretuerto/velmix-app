<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacTenantAssignmentSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_assign_role_to_user_in_tenant_scope(): void
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

        $this->assertDatabaseHas('tenant_user_role', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
        ]);
    }
}
