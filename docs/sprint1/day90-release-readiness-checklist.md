# Day 90 Release Readiness Checklist

## Validacion base

- Ejecutar `composer run velmix:qa`
- Ejecutar `composer run velmix:ci`
- Ejecutar `composer run velmix:ci:mysql` cuando el entorno soporte MySQL
- Revisar `php artisan route:list --except-vendor`
- Confirmar que `X-Tenant-Id` siga documentado en OpenAPI y guia API
- Confirmar que `/docs/operations-runbook` y `/docs/deployment-rollback` sean accesibles con sesion y permiso

## Cobertura funcional minima

- Tenant y RBAC
- Inventario y lotes
- POS contado y credito
- Caja y movimientos manuales
- Compras, recepciones y devoluciones
- Billing con vouchers y notas de credito
- Reportes operativos
- Auditoria transversal

## Verificaciones manuales sugeridas

- Venta POS con `payment_method = cash`
- Venta POS con `payment_method = credit`
- Cobranza en efectivo con caja abierta
- Recepcion de compra con lote nuevo
- Emision de voucher y dispatch simulado
- Nota de credito parcial
- Consulta de dashboard diario
- Lectura de timeline de auditoria

## Señales de salida aceptable

- `migrate:fresh --seed` en verde
- suite completa en verde
- docs internas accesibles desde `/docs`
- runbooks internos accesibles desde `/docs/operations-runbook` y `/docs/deployment-rollback`
- contrato OpenAPI actualizado
- README ya no depende del boilerplate de Laravel
- `system:alerts --json` sin criticos inesperados
