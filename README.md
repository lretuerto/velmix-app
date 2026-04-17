# VELMiX ERP

Backend SaaS multi-tenant para operaciones farmacéuticas, construido sobre Laravel 12. El proyecto ya cubre RBAC por tenant, inventario por producto y lote, POS con FIFO y productos controlados, compras, cuentas por pagar/cobrar, caja, billing interno, auditoría operativa y reportes.

## Estado actual

- Arquitectura multi-tenant por `X-Tenant-Id`
- Autorización por roles y permisos con RBAC por tenant
- Inventario con lotes, vencimiento, inmovilización y movimientos
- POS con ventas contado/crédito, aprobaciones y notas de crédito
- Compras con órdenes, recepciones, devoluciones, créditos y cuentas por pagar
- Caja con aperturas, cierres, arqueo por denominaciones y movimientos manuales
- Caja con guard de una sola sesion abierta por tenant, reforzado tambien a nivel BD
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
- SQLite para desarrollo rapido
- MySQL 8.0+ para la validacion transaccional de CI y entornos cercanos a produccion

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
  - `POST /auth/tokens/{token}/rotate`
  - `DELETE /auth/tokens/{token}`
- La gestión de tokens requiere el permiso `security.api-token.manage` y hoy queda reservada a perfiles administrativos del tenant
- Los API tokens ya no quedan permanentes por omisión: el backend asigna vencimiento por defecto a 30 días y no acepta expiraciones mayores a 90 días
- La rotación administrativa ya permite intervenir tokens de otros usuarios del mismo tenant
- Validación de contexto actual:
  - `GET /auth/me`
- `GET /auth/me` y `GET /tenant/ping` requieren `security.context.read`; un bearer token con `abilities` limitadas debe incluirlo explícitamente
- Si una request trae sesión y bearer token al mismo tiempo, el bearer token tiene prioridad
- Operaciones críticas soportan `Idempotency-Key` para evitar duplicados accidentales:
  - `POST /pos/sales`
  - `POST /billing/vouchers`
  - `POST /billing/credit-notes`
  - `POST /sales/receivables/{receivable}/payments`
  - `POST /purchases/payables/{payable}/payments`
  - `POST /cash/movements`

## Documentación disponible

- Portal de docs interno autenticado por sesión web: `GET /docs`
- OpenAPI YAML autenticado por sesión web: `GET /docs/openapi.yaml`
- Guía operativa API autenticada por sesión web: `GET /docs/api-guide`
- Checklist de release autenticado por sesión web: `GET /docs/release-readiness`
- Runbook de operacion autenticado por sesión web: `GET /docs/operations-runbook`
- Runbook de despliegue y rollback autenticado por sesión web: `GET /docs/deployment-rollback`
- El portal de docs exige `X-Tenant-Id`, membresía al tenant y permiso `security.docs.read`
- Health y readiness:
  - `GET /health/live`
  - `GET /health/ready`
- `GET /health/ready` es publico pero resumido; el detalle completo queda para `php artisan system:readiness --json`
- Todas las respuestas ahora devuelven `X-Request-Id` para correlación operativa
- Logs estructurados disponibles via canales `stderr_json` y `daily_json`
- `composer run velmix:preflight` ahora valida tambien:
  - coherencia de `QUEUE_CONNECTION`
  - existencia de tablas de cola si el driver es `database`
  - presencia de logging estructurado en entornos no locales
  - write-paths criticos (`storage`, `storage/logs`, `bootstrap/cache`)
- Worker manual outbox: `php artisan billing:dispatch-outbox --limit=20`
- Worker manual de reconciliación billing: `php artisan billing:reconcile-pending --limit=20`
- Script de readiness: `composer run velmix:readiness`
- Script de preflight de release: `composer run velmix:preflight`
- Script de alertas operativas: `composer run velmix:alerts`
- Script de pruning conservador: `composer run velmix:prune`
- Script de lint de estilo: `composer run velmix:lint`
- Script de lint completo del repo: `composer run velmix:lint:full`
- Script de auditoria de dependencias: `composer run velmix:audit`
- Script de validacion del scheduler: `composer run velmix:schedule`
- Script de validación outbox: `composer run velmix:outbox` no falla si la base aún no fue migrada
- `php artisan system:alerts --fail-on-critical` queda para CI o chequeos manuales; el scheduler solo observa y no degrada `schedule:run`
- Perfil/provider billing por tenant:
  - `GET /billing/provider-profile`
  - `PUT /billing/provider-profile`
  - `POST /billing/provider-profile/check`
  - `POST /billing/vouchers/{voucher}/reconcile`
  - `POST /billing/credit-notes/{creditNote}/reconcile`
  - `POST /billing/reconcile-pending`
  - las lecturas redactan `credentials` y exponen solo metadata segura
  - `GET /billing/outbox/provider-trace`
  - `GET /billing/provider-metrics`
  - `GET /billing/outbox/{event}/lineage`
- `GET /admin/team/roles`
- `GET /admin/team/users`
- `POST /admin/team/users`
- `POST /admin/team/users/{user}/roles`
- `GET /admin/team/invitations`
- `POST /admin/team/invitations`
- `POST /admin/team/invitations/{invitation}/revoke`
- `POST /team/invitations/accept`
- el bootstrap directo sigue restringido a usuarios nuevos o ya miembros del tenant actual
- el attach formal de usuarios existentes de otros tenants ahora pasa por invitacion aceptada por el propio usuario
  - el bootstrap no adjunta directamente usuarios existentes de otros tenants ni altera su identidad global por email
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
  - `GET /reports/operations-control-tower/briefing`
  - `GET /reports/operations-control-tower/briefing/export`
  - `GET /reports/operations-control-tower/history`
  - `GET /reports/operations-control-tower/compare`
  - `POST /reports/operations-control-tower/snapshots`
  - `GET /reports/operations-control-tower/snapshots`
  - `GET /reports/operations-control-tower/snapshots/{snapshot}`
  - `GET /reports/operations-control-tower/snapshots/{snapshot}/export`
  - `GET /reports/operations-control-tower/snapshots/{snapshot}/compare`
  - `GET /reports/operations-control-tower/snapshots/{snapshot}/compare/export`
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
composer run velmix:concurrency
composer run velmix:qa
composer run velmix:readiness
composer run velmix:preflight
composer run velmix:alerts
composer run velmix:prune
composer run velmix:lint
composer run velmix:lint:full
composer run velmix:audit
composer run velmix:outbox
composer run velmix:reconcile
composer run velmix:ci
composer run velmix:ci:mysql
composer run velmix:schedule
composer run velmix:routes
```

`velmix:qa` ejecuta la secuencia estándar de validación del proyecto:

1. `php artisan migrate:fresh --seed`
2. `php artisan test`

`velmix:ci` encadena la validación completa orientada a pipeline:

1. `composer validate --no-check-publish`
2. `composer run velmix:qa`
3. `composer run velmix:reset`
4. `composer run velmix:schedule`
5. `composer run velmix:preflight`
6. `composer run velmix:routes`
7. `composer run velmix:readiness`
8. `composer run velmix:alerts`
9. `composer run velmix:prune`
10. `composer run velmix:outbox`
11. `composer run velmix:reconcile`

`velmix:ci:mysql` reutiliza la misma secuencia sobre MySQL, agrega la suite `concurrency` y rehidrata el esquema antes del bloque operativo, para validar locks, unicidad e idempotencia en un engine mas parecido a produccion.

## Operacion programada

- `billing:dispatch-outbox --limit=20 --graceful-if-unmigrated` cada minuto
- `billing:reconcile-pending --limit=20 --graceful-if-unmigrated` cada cinco minutos
- `system:alerts` cada cinco minutos
- `platform:prune-operational-data` diariamente a las `03:15`
- el scheduler usa `withoutOverlapping()` con TTL explicito para evitar locks huérfanos de 24 horas tras un crash
- puede habilitarse `VELMIX_SCHEDULER_ON_ONE_SERVER=true` en despliegues multi-nodo con cache compartido y locks atomicos
- `composer run velmix:preflight` valida que `VELMIX_SCHEDULER_ON_ONE_SERVER=true` no quede montado sobre stores locales como `file` o `array`
- tambien detecta `QUEUE_CONNECTION` invalido, tablas de cola ausentes, logging no estructurado en entorno productivo y paths no escribibles
- el pruning conservador ahora incluye `outbox_attempts` ademas de claves de idempotencia, invitaciones y snapshots
- artefactos operativos versionados en [`ops/README.md`](C:\Users\user\Desktop\velmix-app\ops\README.md), [`ops/systemd/velmix-scheduler.service`](C:\Users\user\Desktop\velmix-app\ops\systemd\velmix-scheduler.service) y [`ops/scripts/post-deploy.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\post-deploy.sh)
- despliegue reproducible versionado tambien en:
  - [`ops/systemd/velmix-app.env.example`](C:\Users\user\Desktop\velmix-app\ops\systemd\velmix-app.env.example)
  - [`ops/systemd/velmix-queue-worker.service`](C:\Users\user\Desktop\velmix-app\ops\systemd\velmix-queue-worker.service)
  - [`ops/systemd/velmix-backend.target`](C:\Users\user\Desktop\velmix-app\ops\systemd\velmix-backend.target)
  - [`ops/scripts/install-systemd-units.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\install-systemd-units.sh)
  - [`ops/scripts/bootstrap-shared-path.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\bootstrap-shared-path.sh)
  - [`ops/scripts/prepare-release.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\prepare-release.sh)
  - [`ops/scripts/promote-release.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\promote-release.sh)
  - [`ops/scripts/rollback-to-previous-release.sh`](C:\Users\user\Desktop\velmix-app\ops\scripts\rollback-to-previous-release.sh)

Para despliegues con logs estructurados se recomienda un stack como:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,stderr_json
```

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
- Billing replay: regeneracion y reemision controlada solo para documentos no aceptados
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
- Operations control tower briefing: paquete ejecutivo live con tendencia y drift contra snapshots usando las ventanas solicitadas cuando difieren de la captura
- Operations control tower history/compare: tendencia diaria y delta entre fechas para el tablero maestro
- Operations control tower snapshots: capturas persistidas y exportables del tablero maestro
- Operations control tower snapshot index: conteo total real y filtros por estado, fecha y etiqueta
- Operations control tower snapshot compare: drift entre snapshots guardados y estado live
- API tokens: bearer tokens con `abilities` limitan rutas protegidas por permiso; soportan `*` y prefijos `modulo.*`
- API tokens: los administradores del tenant pueden listar, revocar y rotar tokens del tenant; las respuestas exponen `owner`, `status`, `expires_at` y `last_used_at`
- Auditoría: timeline transversal por tenant

## Validación recomendada antes de publicar cambios

```powershell
composer run velmix:lint
composer run velmix:qa
php artisan route:list --except-vendor
```

`velmix:lint` hoy endurece de forma incremental el slice operativo y de plataforma.  
`velmix:lint:full` queda disponible para atacar la deuda historica de estilo de todo el repo en una iteracion dedicada.

## Notas

- La especificación OpenAPI documenta los endpoints prioritarios y las convenciones de la plataforma.
- Para la superficie completa vigente, usar `php artisan route:list --except-vendor`.
