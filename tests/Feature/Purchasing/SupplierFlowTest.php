<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_supplier_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/suppliers', [
                'tax_id' => '20123456789',
                'name' => 'Distribuidora Norte SAC',
            ])
            ->assertOk()
            ->assertJsonPath('data.tax_id', '20123456789')
            ->assertJsonPath('data.name', 'Distribuidora Norte SAC');

        $this->assertDatabaseHas('suppliers', [
            'tenant_id' => 10,
            'tax_id' => '20123456789',
        ]);
    }

    public function test_warehouse_user_can_list_only_current_tenant_suppliers(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');

        DB::table('suppliers')->insert([
            [
                'tenant_id' => 10,
                'tax_id' => '20111111111',
                'name' => 'Proveedor Tenant 10',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 20,
                'tax_id' => '20222222222',
                'name' => 'Proveedor Tenant 20',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/suppliers')
            ->assertOk()
            ->assertJsonFragment([
                'tax_id' => '20111111111',
                'name' => 'Proveedor Tenant 10',
            ])
            ->assertJsonMissing([
                'tax_id' => '20222222222',
            ]);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);
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
