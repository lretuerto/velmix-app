# Backend Operations Runbook

## Objetivo

Este runbook describe como operar el backend de VELMiX en regimen continuo sin romper trazabilidad ni integridad operativa.

## Scheduler esperado

Frecuencias recomendadas:

- `billing:dispatch-outbox --limit=20 --graceful-if-unmigrated` cada minuto
- `billing:reconcile-pending --limit=20 --graceful-if-unmigrated` cada cinco minutos
- `system:alerts` cada cinco minutos
- `platform:prune-operational-data` cada dia a las `03:15`

## Secuencia de observacion operativa

1. Verificar liveness:
   - `GET /health/live`
2. Verificar readiness resumido:
   - `GET /health/ready`
3. Verificar detalle tecnico:
   - `php artisan system:readiness --json`
4. Revisar alertas cross-tenant:
   - `php artisan system:alerts --json`
5. Revisar outbox:
   - `php artisan billing:dispatch-outbox --limit=20 --graceful-if-unmigrated`
6. Revisar reconciliacion:
   - `php artisan billing:reconcile-pending --limit=20 --graceful-if-unmigrated`
7. Revisar housekeeping:
   - `php artisan platform:prune-operational-data --pretend --json`

## Politica de alertas

- `system:alerts` es observacional para scheduler.
- `system:alerts --fail-on-critical` se reserva para chequeos manuales, gates de CI o validaciones de despliegue.
- Una alerta critica no debe tumbar `schedule:run`; debe abrir diagnostico y respuesta operativa.

## Politica de retencion

Targets actuales:

- `idempotency_keys`: prune conservador cuando no estan `in_progress`
- `tenant_user_invitations`: prune de estados terminales antiguos
- `operations_control_tower_snapshots`: prune por antiguedad

Variables asociadas:

- `VELMIX_RETENTION_IDEMPOTENCY_DAYS`
- `VELMIX_RETENTION_TEAM_INVITATIONS_DAYS`
- `VELMIX_RETENTION_CONTROL_TOWER_SNAPSHOTS_DAYS`

## Logs estructurados

Configuracion recomendada:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,stderr_json
LOG_LEVEL=info
```

Contexto minimo agregado:

- `request_id`
- `request_method`
- `request_path`
- `request_ip`
- `tenant_id`
- `tenant_code`
- `route_uri`
- `auth_mode`
- `user_id`
- `api_token_id`

## Incidentes frecuentes

### Outbox pendiente creciendo

1. Ejecutar `php artisan system:alerts --json`
2. Revisar `GET /billing/outbox/summary`
3. Ejecutar `php artisan billing:dispatch-outbox --limit=20`
4. Si persiste `pending`, ejecutar `php artisan billing:reconcile-pending --limit=20`
5. Revisar `GET /billing/provider-metrics` y `GET /billing/outbox/provider-trace`

### Readiness degradado

1. Ejecutar `php artisan system:readiness --json`
2. Confirmar tablas minimas y conectividad DB
3. Suspender trafico de mutacion si el problema es de esquema

### Retencion con crecimiento inesperado

1. Ejecutar `php artisan platform:prune-operational-data --pretend --json`
2. Confirmar volumen por tabla
3. Ejecutar prune real solo cuando el conteo sea consistente con la ventana esperada

## Comandos de referencia

```powershell
composer run velmix:readiness
composer run velmix:alerts
composer run velmix:outbox
composer run velmix:reconcile
composer run velmix:prune
php artisan schedule:list
```
