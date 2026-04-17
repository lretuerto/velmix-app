# Backend Operations Runbook

## Objetivo

Este runbook define como operar el backend de VELMiX en regimen continuo sin comprometer integridad transaccional, trazabilidad ni continuidad del scheduler.

## Topologia operativa recomendada

### Scheduler

Ejecutar una sola estrategia, no ambas al mismo tiempo:

- Linux con cron:
  - `* * * * * php /var/www/velmix/artisan schedule:run >> /dev/null 2>&1`
- Proceso dedicado:
  - `php artisan schedule:work`

Para despliegues multi-nodo:

- activar `VELMIX_SCHEDULER_ON_ONE_SERVER=true`
- usar un store de cache compartido y con locks atomicos
- no habilitar `onOneServer` con stores locales aislados por nodo

### Workers operativos

Procesos esperados:

- `php artisan billing:dispatch-outbox --limit=20 --graceful-if-unmigrated`
- `php artisan billing:reconcile-pending --limit=20 --graceful-if-unmigrated`

Pueden correrse manualmente, por scheduler o bajo supervisión de proceso si se desea un lazo más estricto de operación.

## Scheduler configurado

Frecuencias por defecto:

- `billing:dispatch-outbox --limit=20 --graceful-if-unmigrated` cada minuto
- `billing:reconcile-pending --limit=20 --graceful-if-unmigrated` cada cinco minutos
- `system:alerts` cada cinco minutos
- `system:dispatch-alerts` cada cinco minutos
- `platform:prune-operational-data` cada dia a las `03:15`

Candados y seguridad operativa:

- el scheduler ahora usa `withoutOverlapping()` con TTL explicito, no el default de 24 horas
- `dispatch` expira overlap a los `10` minutos
- `reconcile` expira overlap a los `15` minutos
- `alerts` expira overlap a los `10` minutos
- `prune` expira overlap a los `180` minutos

Variables asociadas:

- `VELMIX_SCHEDULER_TIMEZONE`
- `VELMIX_SCHEDULER_ON_ONE_SERVER`
- `VELMIX_SCHEDULER_DISPATCH_LIMIT`
- `VELMIX_SCHEDULER_DISPATCH_EVERY_MINUTES`
- `VELMIX_SCHEDULER_DISPATCH_OVERLAP_MINUTES`
- `VELMIX_SCHEDULER_RECONCILE_LIMIT`
- `VELMIX_SCHEDULER_RECONCILE_EVERY_MINUTES`
- `VELMIX_SCHEDULER_RECONCILE_OVERLAP_MINUTES`
- `VELMIX_SCHEDULER_ALERTS_EVERY_MINUTES`
- `VELMIX_SCHEDULER_ALERTS_OVERLAP_MINUTES`
- `VELMIX_SCHEDULER_ALERT_DISPATCH_EVERY_MINUTES`
- `VELMIX_SCHEDULER_ALERT_DISPATCH_OVERLAP_MINUTES`
- `VELMIX_SCHEDULER_PRUNE_AT`
- `VELMIX_SCHEDULER_PRUNE_OVERLAP_MINUTES`
- `VELMIX_ALERT_NOTIFY_CHANNELS`
- `VELMIX_ALERT_NOTIFY_MIN_SEVERITY`
- `VELMIX_ALERT_NOTIFY_COOLDOWN_MINUTES`
- `VELMIX_ALERT_NOTIFY_LOG_CHANNEL`
- `VELMIX_ALERT_WEBHOOK_URL`
- `VELMIX_ALERT_WEBHOOK_TIMEOUT_SECONDS`

## Secuencia de observacion operativa

1. Verificar liveness:
   - `GET /health/live`
2. Verificar readiness resumido:
   - `GET /health/ready`
3. Verificar detalle tecnico:
   - `php artisan system:readiness --json`
4. Ejecutar preflight de release/configuracion:
   - `php artisan system:preflight --json`
5. Verificar tareas registradas:
   - `php artisan schedule:list`
6. Revisar alertas cross-tenant:
   - `php artisan system:alerts --json`
7. Despachar alertas salientes:
   - `php artisan system:dispatch-alerts --json`
8. Revisar snapshot de observabilidad:
   - `php artisan system:observability-report --json`
9. Revisar outbox:
   - `php artisan billing:dispatch-outbox --limit=20 --graceful-if-unmigrated`
10. Revisar reconciliacion:
   - `php artisan billing:reconcile-pending --limit=20 --graceful-if-unmigrated`
11. Revisar housekeeping:
   - `php artisan platform:prune-operational-data --pretend --json`

## Politica de alertas

- `system:alerts` es observacional para scheduler
- `system:dispatch-alerts` materializa notificaciones segun canal/configuracion y tambien es observacional para scheduler
- `system:alerts --fail-on-critical` se reserva para chequeos manuales, gates de CI o validaciones de despliegue
- una alerta critica no debe tumbar `schedule:run`; debe abrir diagnostico y respuesta operativa
- `system:preflight --fail-on-warning` si puede usarse como gate de despliegue porque valida coherencia de plataforma sin mezclar backlog de negocio
- gate recomendado de despliegue:
  - `php artisan system:preflight --json --fail-on-warning`
- notificaciones soportadas:
  - `log` para persistencia estructurada local o centralizada
  - `webhook` para integracion con relays, Slack gateways o plataformas externas
- cooldown defensivo:
  - deduplica por `code + severity + tenant + path + channel`
  - puede forzarse manualmente con `php artisan system:dispatch-alerts --force`

## Politica de preflight

Checks actuales:

- readiness resumido del backend
- `APP_DEBUG` habilitado fuera de `local/testing`
- coherencia entre `VELMIX_SCHEDULER_ON_ONE_SERVER=true` y un store de cache compartido
- `QUEUE_CONNECTION` valido y durable para entorno no local
- tablas de cola listas cuando el driver es `database`
- canal de logs activo y stack sin referencias invalidas
- logging estructurado habilitado en entornos no locales
- write-paths criticos presentes y escribibles

Codigos esperados:

- `app_debug_enabled`
- `cache_store_missing`
- `scheduler_lock_store_not_shared`
- `queue_connection_missing`
- `queue_driver_missing`
- `queue_storage_check_failed`
- `queue_storage_not_ready`
- `queue_connection_not_async`
- `logging_channel_missing`
- `logging_stack_channel_missing`
- `structured_logging_not_enabled`
- `writable_path_missing`
- `writable_path_not_writable`

## Politica de retencion

Targets actuales:

- `idempotency_keys`: prune conservador cuando no estan `in_progress`
- `outbox_attempts`: prune por antiguedad, al ser evidencia derivada y no estado canonico
- `tenant_user_invitations`: prune de estados terminales antiguos
- `operations_control_tower_snapshots`: prune por antiguedad

Variables asociadas:

- `VELMIX_RETENTION_IDEMPOTENCY_DAYS`
- `VELMIX_RETENTION_OUTBOX_ATTEMPTS_DAYS`
- `VELMIX_RETENTION_TEAM_INVITATIONS_DAYS`
- `VELMIX_RETENTION_CONTROL_TOWER_SNAPSHOTS_DAYS`

Regla de activacion:

- en un entorno nuevo, ejecutar primero `platform:prune-operational-data --pretend --json`
- pasar a prune real solo cuando el conteo esperado sea consistente con la ventana de retencion definida

## Logs estructurados

Configuracion recomendada:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,stderr_json,daily_json
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

## Snapshot tecnico de observabilidad

- `php artisan system:observability-report --json` resume:
  - estado agregado entre preflight y alertas
  - correlacion por `X-Request-Id`
  - configuracion efectiva de logging estructurado
  - conexion y tablas de cola
  - frecuencias del scheduler
  - canales de notificacion y cooldown

## Supervision recomendada

### systemd para scheduler continuo

```ini
[Unit]
Description=VELMiX Scheduler
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/velmix/current
EnvironmentFile=-/etc/velmix/velmix.env
ExecStartPre=/usr/bin/php artisan system:preflight --json --fail-on-critical
ExecStart=/usr/bin/php artisan schedule:work
ExecStop=/usr/bin/php artisan schedule:interrupt
Restart=always
RestartSec=5

[Install]
WantedBy=velmix-backend.target
```

### systemd para queue worker persistente

```ini
[Unit]
Description=VELMiX queue worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/velmix/current
EnvironmentFile=-/etc/velmix/velmix.env
ExecStartPre=/usr/bin/php artisan system:preflight --json --fail-on-critical
ExecStart=/usr/bin/php artisan queue:work --queue=${VELMIX_QUEUE_NAME:-default} --sleep=${VELMIX_QUEUE_SLEEP:-1} --tries=${VELMIX_QUEUE_TRIES:-1} --timeout=${VELMIX_QUEUE_TIMEOUT:-120} --max-time=${VELMIX_QUEUE_MAX_TIME:-3600} --no-interaction
ExecReload=/usr/bin/php artisan queue:restart
Restart=always
RestartSec=5

[Install]
WantedBy=velmix-backend.target
```

### Asset bundle recomendado

- environment file base: `ops/systemd/velmix-app.env.example`
- target coordinado: `ops/systemd/velmix-backend.target`
- scheduler: `ops/systemd/velmix-scheduler.service`
- queue worker: `ops/systemd/velmix-queue-worker.service`
- restart hook: `ops/systemd/velmix-queue-restart.service`
- instalacion de units: `ops/scripts/install-systemd-units.sh`
- bootstrap compartido: `ops/scripts/bootstrap-shared-path.sh`
- prepare/promote/rollback:
  - `ops/scripts/prepare-release.sh`
  - `ops/scripts/promote-release.sh`
  - `ops/scripts/rollback-to-previous-release.sh`

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

### Scheduler detenido o bloqueado

1. Ejecutar `php artisan schedule:list`
2. Verificar proceso `schedule:work` o cron
3. Validar que no haya locks huérfanos por TTL demasiado largo
4. Si el despliegue dejo un worker viejo, ejecutar `php artisan schedule:interrupt` y reiniciar el proceso supervisor

### Retencion con crecimiento inesperado

1. Ejecutar `php artisan platform:prune-operational-data --pretend --json`
2. Confirmar volumen por tabla
3. Revisar especialmente `outbox_attempts` antes de bajar la ventana de retencion
4. Ejecutar prune real solo cuando el conteo sea consistente con la ventana esperada

## Comandos de referencia

```powershell
composer run velmix:readiness
composer run velmix:dispatch-alerts
composer run velmix:observability
composer run velmix:preflight
composer run velmix:alerts
composer run velmix:prune
composer run velmix:outbox
composer run velmix:reconcile
composer run velmix:schedule
php artisan schedule:list
php artisan schedule:work
php artisan schedule:interrupt
php artisan queue:restart
ops/scripts/install-systemd-units.sh
ops/scripts/bootstrap-shared-path.sh
ops/scripts/prepare-release.sh <release-path>
ops/scripts/promote-release.sh <release-path>
ops/scripts/rollback-to-previous-release.sh
```
