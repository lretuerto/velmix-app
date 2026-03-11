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
            ['code' => 'pos.sale.read', 'name' => 'Consultar ventas POS'],
            ['code' => 'pos.sale.approve', 'name' => 'Aprobar venta POS'],
            ['code' => 'inventory.product.create', 'name' => 'Crear productos de inventario'],
            ['code' => 'inventory.product.read', 'name' => 'Ver productos de inventario'],
            ['code' => 'inventory.lot.create', 'name' => 'Crear lotes de inventario'],
            ['code' => 'inventory.lot.read', 'name' => 'Ver lotes de inventario'],
            ['code' => 'billing.voucher.issue', 'name' => 'Emitir comprobantes electronicos'],
            ['code' => 'billing.voucher.read', 'name' => 'Consultar comprobantes electronicos'],
            ['code' => 'billing.outbox.dispatch', 'name' => 'Despachar eventos de facturacion'],
            ['code' => 'billing.outbox.read', 'name' => 'Consultar eventos outbox de facturacion'],
            ['code' => 'cash.session.open', 'name' => 'Abrir caja'],
            ['code' => 'cash.session.close', 'name' => 'Cerrar caja'],
            ['code' => 'cash.session.read', 'name' => 'Consultar caja'],
            ['code' => 'reports.daily.read', 'name' => 'Consultar resumen diario operativo'],
            ['code' => 'reports.inventory.read', 'name' => 'Consultar alertas de inventario'],
            ['code' => 'purchase.supplier.create', 'name' => 'Crear proveedores'],
            ['code' => 'purchase.supplier.read', 'name' => 'Consultar proveedores'],
            ['code' => 'purchase.order.create', 'name' => 'Crear ordenes de compra'],
            ['code' => 'purchase.order.read', 'name' => 'Consultar ordenes de compra'],
            ['code' => 'purchase.payable.read', 'name' => 'Consultar cuentas por pagar'],
            ['code' => 'purchase.payable.pay', 'name' => 'Registrar pagos a proveedores'],
            ['code' => 'purchase.receipt.create', 'name' => 'Registrar recepcion de compra'],
            ['code' => 'purchase.receipt.read', 'name' => 'Consultar recepciones de compra'],
            ['code' => 'stock.move.create', 'name' => 'Crear movimiento de stock'],
            ['code' => 'stock.move.read', 'name' => 'Consultar movimientos de stock'],
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
            'ADMIN' => ['pos.sale.execute', 'pos.sale.read', 'pos.sale.approve', 'inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'billing.voucher.issue', 'billing.voucher.read', 'billing.outbox.dispatch', 'billing.outbox.read', 'cash.session.open', 'cash.session.close', 'cash.session.read', 'reports.daily.read', 'reports.inventory.read', 'purchase.supplier.create', 'purchase.supplier.read', 'purchase.order.create', 'purchase.order.read', 'purchase.payable.read', 'purchase.payable.pay', 'purchase.receipt.create', 'purchase.receipt.read', 'stock.move.create', 'stock.move.read', 'rbac.role.assign', 'rbac.permission.manage'],
            'CAJERO' => ['pos.sale.execute', 'pos.sale.read', 'billing.voucher.issue', 'billing.voucher.read', 'cash.session.open', 'cash.session.close', 'cash.session.read'],
            'ALMACENERO' => ['inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'reports.inventory.read', 'purchase.supplier.read', 'purchase.order.read', 'purchase.payable.read', 'purchase.receipt.create', 'purchase.receipt.read', 'stock.move.create', 'stock.move.read'],
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
