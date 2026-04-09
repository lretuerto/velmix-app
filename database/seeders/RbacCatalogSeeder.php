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
            ['code' => 'sales.customer.create', 'name' => 'Crear clientes de venta'],
            ['code' => 'sales.customer.read', 'name' => 'Consultar clientes de venta'],
            ['code' => 'sales.customer.update', 'name' => 'Actualizar clientes de venta'],
            ['code' => 'sales.receivable.read', 'name' => 'Consultar cuentas por cobrar'],
            ['code' => 'sales.receivable.pay', 'name' => 'Registrar cobranzas'],
            ['code' => 'sales.receivable.follow-up.create', 'name' => 'Registrar seguimientos de cobranza'],
            ['code' => 'inventory.product.create', 'name' => 'Crear productos de inventario'],
            ['code' => 'inventory.product.read', 'name' => 'Ver productos de inventario'],
            ['code' => 'inventory.lot.create', 'name' => 'Crear lotes de inventario'],
            ['code' => 'inventory.lot.read', 'name' => 'Ver lotes de inventario'],
            ['code' => 'billing.voucher.issue', 'name' => 'Emitir comprobantes electronicos'],
            ['code' => 'billing.voucher.read', 'name' => 'Consultar comprobantes electronicos'],
            ['code' => 'billing.credit-note.issue', 'name' => 'Emitir notas de credito'],
            ['code' => 'billing.credit-note.read', 'name' => 'Consultar notas de credito'],
            ['code' => 'billing.outbox.dispatch', 'name' => 'Despachar eventos de facturacion'],
            ['code' => 'billing.outbox.read', 'name' => 'Consultar eventos outbox de facturacion'],
            ['code' => 'billing.provider.manage', 'name' => 'Configurar proveedor de facturacion'],
            ['code' => 'cash.session.open', 'name' => 'Abrir caja'],
            ['code' => 'cash.session.close', 'name' => 'Cerrar caja'],
            ['code' => 'cash.session.read', 'name' => 'Consultar caja'],
            ['code' => 'cash.movement.create', 'name' => 'Registrar movimientos de caja'],
            ['code' => 'cash.movement.read', 'name' => 'Consultar movimientos de caja'],
            ['code' => 'audit.timeline.read', 'name' => 'Consultar bitacora operativa'],
            ['code' => 'reports.daily.read', 'name' => 'Consultar resumen diario operativo'],
            ['code' => 'reports.due-reminders.read', 'name' => 'Consultar vencimientos operativos'],
            ['code' => 'reports.promise-compliance.read', 'name' => 'Consultar cumplimiento de promesas'],
            ['code' => 'reports.inventory.read', 'name' => 'Consultar alertas de inventario'],
            ['code' => 'reports.billing-operations.read', 'name' => 'Consultar operaciones ejecutivas de billing'],
            ['code' => 'reports.billing-operations.manage', 'name' => 'Gestionar escalaciones de billing'],
            ['code' => 'reports.finance-operations.read', 'name' => 'Consultar operaciones financieras'],
            ['code' => 'reports.finance-operations.manage', 'name' => 'Gestionar operaciones financieras'],
            ['code' => 'reports.operations-control-tower.read', 'name' => 'Consultar tablero maestro operativo'],
            ['code' => 'reports.operations-control-tower.manage', 'name' => 'Gestionar snapshots del tablero maestro operativo'],
            ['code' => 'reports.operations-escalations.read', 'name' => 'Consultar escalaciones operativas unificadas'],
            ['code' => 'reports.operations-escalations.manage', 'name' => 'Gestionar escalaciones operativas unificadas'],
            ['code' => 'reports.receivable-risk.read', 'name' => 'Consultar riesgo de cuentas por cobrar'],
            ['code' => 'reports.sales-profitability.read', 'name' => 'Consultar rentabilidad de ventas'],
            ['code' => 'purchase.supplier.create', 'name' => 'Crear proveedores'],
            ['code' => 'purchase.supplier.read', 'name' => 'Consultar proveedores'],
            ['code' => 'purchase.order.create', 'name' => 'Crear ordenes de compra'],
            ['code' => 'purchase.order.read', 'name' => 'Consultar ordenes de compra'],
            ['code' => 'purchase.replenishment.read', 'name' => 'Consultar sugerencias de reabastecimiento'],
            ['code' => 'purchase.payable.read', 'name' => 'Consultar cuentas por pagar'],
            ['code' => 'purchase.payable.pay', 'name' => 'Registrar pagos a proveedores'],
            ['code' => 'purchase.payable.follow-up.create', 'name' => 'Registrar seguimientos de pago a proveedores'],
            ['code' => 'purchase.receipt.create', 'name' => 'Registrar recepcion de compra'],
            ['code' => 'purchase.receipt.read', 'name' => 'Consultar recepciones de compra'],
            ['code' => 'purchase.return.create', 'name' => 'Registrar devoluciones a proveedor'],
            ['code' => 'purchase.return.read', 'name' => 'Consultar devoluciones a proveedor'],
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
            'ADMIN' => ['pos.sale.execute', 'pos.sale.read', 'pos.sale.approve', 'sales.customer.create', 'sales.customer.read', 'sales.customer.update', 'sales.receivable.read', 'sales.receivable.pay', 'sales.receivable.follow-up.create', 'inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'billing.voucher.issue', 'billing.voucher.read', 'billing.credit-note.issue', 'billing.credit-note.read', 'billing.outbox.dispatch', 'billing.outbox.read', 'billing.provider.manage', 'cash.session.open', 'cash.session.close', 'cash.session.read', 'cash.movement.create', 'cash.movement.read', 'audit.timeline.read', 'reports.daily.read', 'reports.due-reminders.read', 'reports.promise-compliance.read', 'reports.inventory.read', 'reports.billing-operations.read', 'reports.billing-operations.manage', 'reports.finance-operations.read', 'reports.finance-operations.manage', 'reports.operations-control-tower.read', 'reports.operations-control-tower.manage', 'reports.operations-escalations.read', 'reports.operations-escalations.manage', 'reports.receivable-risk.read', 'reports.sales-profitability.read', 'purchase.supplier.create', 'purchase.supplier.read', 'purchase.order.create', 'purchase.order.read', 'purchase.replenishment.read', 'purchase.payable.read', 'purchase.payable.pay', 'purchase.payable.follow-up.create', 'purchase.receipt.create', 'purchase.receipt.read', 'purchase.return.create', 'purchase.return.read', 'stock.move.create', 'stock.move.read', 'rbac.role.assign', 'rbac.permission.manage'],
            'CAJERO' => ['pos.sale.execute', 'pos.sale.read', 'sales.customer.create', 'sales.customer.read', 'sales.customer.update', 'sales.receivable.read', 'sales.receivable.pay', 'sales.receivable.follow-up.create', 'billing.voucher.issue', 'billing.voucher.read', 'cash.session.open', 'cash.session.close', 'cash.session.read', 'cash.movement.create', 'cash.movement.read'],
            'ALMACENERO' => ['inventory.product.create', 'inventory.product.read', 'inventory.lot.create', 'inventory.lot.read', 'reports.inventory.read', 'purchase.supplier.read', 'purchase.order.read', 'purchase.replenishment.read', 'purchase.payable.read', 'purchase.payable.follow-up.create', 'purchase.receipt.create', 'purchase.receipt.read', 'purchase.return.create', 'purchase.return.read', 'stock.move.create', 'stock.move.read'],
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
