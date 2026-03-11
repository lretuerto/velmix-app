<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['code' => 'pos.sale.execute', 'name' => 'Ejecutar venta POS'],
            ['code' => 'pos.sale.approve', 'name' => 'Aprobar venta POS'],
            ['code' => 'inventory.product.create', 'name' => 'Crear productos de inventario'],
            ['code' => 'inventory.product.read', 'name' => 'Ver productos de inventario'],
            ['code' => 'inventory.lot.create', 'name' => 'Crear lotes de inventario'],
            ['code' => 'inventory.lot.read', 'name' => 'Ver lotes de inventario'],
            ['code' => 'stock.move.create', 'name' => 'Crear movimiento de stock'],
            ['code' => 'rbac.role.assign', 'name' => 'Asignar roles'],
            ['code' => 'rbac.permission.manage', 'name' => 'Gestionar permisos'],
        ];

        $roles = [
            ['code' => 'ADMIN', 'name' => 'Administrador'],
            ['code' => 'CAJERO', 'name' => 'Cajero'],
            ['code' => 'ALMACENERO', 'name' => 'Almacenero'],
        ];

        foreach ($permissions as $p) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $p['code']],
                ['name' => $p['name'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        foreach ($roles as $r) {
            DB::table('roles')->updateOrInsert(
                ['code' => $r['code']],
                ['name' => $r['name'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permIds = DB::table('permissions')->pluck('id', 'code');

        $matrix = [
            'ADMIN' => ['pos.sale.execute', 'pos.sale.approve', 'inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'stock.move.create', 'rbac.role.assign', 'rbac.permission.manage'],
            'CAJERO' => ['pos.sale.execute'],
            'ALMACENERO' => ['inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'stock.move.create'],
        ];

        foreach ($matrix as $roleCode => $permCodes) {
            foreach ($permCodes as $permCode) {
                DB::table('role_permission')->updateOrInsert(
                    [
                        'role_id' => $roleIds[$roleCode],
                        'permission_id' => $permIds[$permCode],
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
