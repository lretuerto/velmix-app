<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_rbac_catalog_seeder_populates_roles_permissions_and_matrix(): void
    {
        $this->seed(\Database\Seeders\RbacCatalogSeeder::class);

        $this->assertDatabaseHas('roles', ['code' => 'ADMIN']);
        $this->assertDatabaseHas('roles', ['code' => 'CAJERO']);
        $this->assertDatabaseHas('roles', ['code' => 'ALMACENERO']);

        $this->assertDatabaseHas('permissions', ['code' => 'pos.sale.execute']);
        $this->assertDatabaseHas('permissions', ['code' => 'pos.sale.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'pos.sale.approve']);
        $this->assertDatabaseHas('permissions', ['code' => 'inventory.product.create']);
        $this->assertDatabaseHas('permissions', ['code' => 'inventory.product.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'inventory.lot.create']);
        $this->assertDatabaseHas('permissions', ['code' => 'inventory.lot.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'billing.voucher.issue']);
        $this->assertDatabaseHas('permissions', ['code' => 'billing.voucher.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'billing.outbox.dispatch']);
        $this->assertDatabaseHas('permissions', ['code' => 'billing.outbox.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'cash.session.open']);
        $this->assertDatabaseHas('permissions', ['code' => 'cash.session.close']);
        $this->assertDatabaseHas('permissions', ['code' => 'cash.session.read']);
        $this->assertDatabaseHas('permissions', ['code' => 'stock.move.create']);
        $this->assertDatabaseHas('permissions', ['code' => 'rbac.role.assign']);

        $adminId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $permId = DB::table('permissions')->where('code', 'rbac.permission.manage')->value('id');

        $this->assertDatabaseHas('role_permission', [
            'role_id' => $adminId,
            'permission_id' => $permId,
        ]);
    }
}
