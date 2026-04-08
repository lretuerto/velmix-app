# VELMiX ERP

Backend SaaS multi-tenant para operaciones farmacéuticas, construido sobre Laravel 12. El proyecto ya cubre RBAC por tenant, inventario por producto y lote, POS con FIFO y productos controlados, compras, cuentas por pagar/cobrar, caja, billing interno, auditoría operativa y reportes.

## Estado actual

- Arquitectura multi-tenant por `X-Tenant-Id`
- Autorización por roles y permisos con RBAC por tenant
- Inventario con lotes, vencimiento, inmovilización y movimientos
- POS con ventas contado/crédito, aprobaciones y notas de crédito
- Compras con órdenes, recepciones, devoluciones, créditos y cuentas por pagar
- Caja con aperturas, cierres, arqueo por denominaciones y movimientos manuales
- Reportes operativos, riesgo, vencimientos, promesas y auditoría transversal
- Dashboard financiero unificado para cobranza y pagos con prioridad operativa
- Workflow operativo para prioridades financieras con acknowledge y resolve
- Historial operativo de prioridades financieras con timeline por entidad
- Metricas financieras de backlog, aging y SLA de resolución
- Escalaciones financieras accionables con severidad y acciones recomendadas
- Workflow manual sobre alertas financieras agregadas
- Historial y metricas de escalaciones financieras agregadas por codigo
- Cola unificada de escalaciones operativas cross-domain para billing y finanzas
- Dashboard ejecutivo de billing con backlog, SLA y comparación sandbox/live
- Escalamiento accionable de billing con prioridades y acciones recomendadas
- Seguimiento manual de escalaciones de billing con acknowledge y resolve
- Historial operativo de escalaciones con timeline, notas y responsables por codigo
- Metricas de escalaciones con backlog activo y SLA de resolucion por workflow
- Billing desacoplado por perfil/provider tenant con outbox operable por lotes

## Requisitos

- PHP 8.2+
- Composer
- SQLite o motor compatible con Laravel

## Inicio rápido

```powershell
composer install
Copy-Item .env.example .env -ErrorAction SilentlyContinue
php artisan key:generate
php artisan migrate:fresh --seed
php artisan test
```

## Convenciones de la API

- Los endpoints de negocio aceptan sesión Laravel o `Authorization: Bearer <token>`
- Los endpoints multi-tenant requieren el header `X-Tenant-Id`
- La mayoría de respuestas siguen el formato `{"data": ...}`
- Los rechazos por contexto/permiso usan `403`, validación `422`, y recursos ajenos `404`

### Tokens API

- Emisión y revocación por sesión en:
  - `GET /auth/tokens`
  - `POST /auth/tokens`
  - `DELETE /auth/tokens/{token}`
- Validación de contexto actual:
  - `GET /auth/me`
- Si una request trae sesión y bearer token al mismo tiempo, el bearer token tiene prioridad

## Documentación disponible

- Portal de docs interno: `GET /docs`
- OpenAPI YAML: `GET /docs/openapi.yaml`
- Guía operativa API: `GET /docs/api-guide`
- Checklist de release: `GET /docs/release-readiness`
- Worker manual outbox: `php artisan billing:dispatch-outbox --limit=20`
- Perfil/provider billing por tenant:
  - `GET /billing/provider-profile`
  - `PUT /billing/provider-profile`
  - `POST /billing/provider-profile/check`
  - `GET /billing/outbox/provider-trace`
  - `GET /billing/provider-metrics`
  - `GET /billing/outbox/{event}/lineage`
  - `GET /reports/finance-operations`
  - `GET /reports/finance-escalations`
  - `GET /reports/finance-escalations/history`
  - `GET /reports/finance-escalation-metrics`
  - `GET /reports/finance-escalations/{code}`
  - `POST /reports/finance-escalations/{code}/acknowledge`
  - `POST /reports/finance-escalations/{code}/resolve`
  - `GET /reports/finance-operations/history`
  - `GET /reports/finance-operations/metrics`
  - `GET /reports/finance-operations/{kind}/{entity}`
  - `GET /reports/finance-operations/{kind}/{entity}/history`
  - `POST /reports/finance-operations/{kind}/{entity}/acknowledge`
  - `POST /reports/finance-operations/{kind}/{entity}/resolve`
  - `GET /reports/operations-control-tower`
  - `GET /reports/operations-escalations`
  - `GET /reports/operations-escalations/history`
  - `GET /reports/operations-escalation-metrics`
  - `GET /reports/operations-escalations/{domain}/{code}`
  - `POST /reports/operations-escalations/{domain}/{code}/acknowledge`
  - `POST /reports/operations-escalations/{domain}/{code}/resolve`
  - `GET /reports/billing-escalations`
  - `GET /reports/billing-escalation-metrics`
  - `GET /reports/billing-escalations/history`
  - `GET /reports/billing-escalations/{code}`
  - `POST /reports/billing-escalations/{code}/acknowledge`
  - `POST /reports/billing-escalations/{code}/resolve`
  - `GET /billing/vouchers/{voucher}/payloads`
  - `POST /billing/vouchers/{voucher}/payloads/regenerate`
  - `POST /billing/vouchers/{voucher}/replay`
  - `GET /billing/credit-notes/{creditNote}/payloads`
  - `POST /billing/credit-notes/{creditNote}/payloads/regenerate`
  - `POST /billing/credit-notes/{creditNote}/replay`

Archivos fuente:

- [`docs/openapi/velmix.openapi.yaml`](C:\Users\user\Desktop\velmix-app\docs\openapi\velmix.openapi.yaml)
- [`docs/api-guide.md`](C:\Users\user\Desktop\velmix-app\docs\api-guide.md)
- [`docs/sprint1/day90-release-readiness-checklist.md`](C:\Users\user\Desktop\velmix-app\docs\sprint1\day90-release-readiness-checklist.md)

## Scripts útiles

```powershell
composer run test
composer run velmix:reset
composer run velmix:test
composer run velmix:qa
composer run velmix:outbox
composer run velmix:routes
```

`velmix:qa` ejecuta la secuencia estándar de validación del proyecto:

1. `php artisan migrate:fresh --seed`
2. `php artisan test`

## Módulos principales

- Seguridad y tenant: middleware `tenant.context`, `tenant.access` y `perm:*`
- Inventario: productos, lotes, stock, inmovilización y trazabilidad
- POS y ventas: ventas FIFO, crédito, cancelaciones y rentabilidad
- Billing: vouchers, outbox y notas de crédito parciales/totales
- Billing providers: perfil por tenant para sandbox/live y outcome por defecto
- Billing health: snapshot de salud y trazabilidad de intentos por provider/environment
- Billing metrics: SLA operativo, backlog, replays y fallos recientes por tenant
- Billing operations report: tendencia diaria y comparativo por environment
- Billing escalations: alertas priorizadas con acciones recomendadas por tenant
- Billing escalation workflow: seguimiento manual de acknowledge y resolve por codigo
- Billing escalation history: timeline operativo, ultima nota y responsables por codigo
- Billing escalation metrics: backlog activo, eventos de workflow y SLA de resolucion
- Billing payloads: snapshots versionados por provider/esquema para voucher y nota de credito
- Billing replay: regeneracion y reemision controlada sin recrear la venta o la nota
- Billing lineage: trazabilidad completa payload -> outbox original -> replay -> intentos
- Compras: proveedores, órdenes, recepciones, devoluciones y créditos
- Caja: aperturas, cierres, cobranzas y movimientos no comerciales
- Reportes: diario, vencimientos, promesas, rentabilidad y riesgo
- Finance operations report: exposicion, promesas y salud de seguimiento para cobranzas/pagos
- Finance operations workflow: gestion manual de prioridades financieras por entidad
- Finance operations history: timeline, notas y responsables por receivable/payable
- Finance operations metrics: backlog activo, aging y SLA de resolución de seguimiento
- Finance escalations: severidad y acciones recomendadas sobre la cola financiera priorizada
- Finance escalation workflow: acknowledge y resolve manual sobre alertas agregadas
- Finance escalation history: timeline, notas y responsables por codigo de alerta
- Finance escalation metrics: backlog activo, aging y SLA de resolucion por alerta
- Operations escalations: cola cross-domain para priorizar billing y finanzas en una sola vista
- Operations escalations workflow: acknowledge y resolve unificado desde la cola cross-domain
- Operations escalations history: timeline, notas y responsables cross-domain por `queue_key`
- Operations escalations metrics: backlog, eventos y SLA agregados entre billing y finanzas
- Operations control tower: tablero maestro con health gates, action center y drill-down cross-domain
- Auditoría: timeline transversal por tenant

## Validación recomendada antes de publicar cambios

```powershell
composer run velmix:qa
php artisan route:list --except-vendor
```

## Notas

- La especificación OpenAPI documenta los endpoints prioritarios y las convenciones de la plataforma.
- Para la superficie completa vigente, usar `php artisan route:list --except-vendor`.
