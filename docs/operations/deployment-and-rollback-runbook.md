# Deployment And Rollback Runbook

## Objetivo

Este documento define una secuencia segura de despliegue y reversa para el backend actual de VELMiX, incluyendo scheduler, workers, housekeeping y rollback controlado.

## Pre-deploy obligatorio

Ejecutar en CI o staging:

```powershell
composer validate --no-check-publish
composer run velmix:qa
composer run velmix:schedule
composer run velmix:preflight
composer run velmix:record-backup:ci
composer run velmix:backup-readiness
composer run velmix:routes
composer run velmix:readiness
composer run velmix:alerts
composer run velmix:restore-drill
composer run velmix:prune
composer run velmix:outbox
composer run velmix:reconcile
```

Si existe lane MySQL disponible:

```powershell
composer run velmix:ci:mysql
```

## Deploy recomendado

### Assets versionados

- environment file base: `ops/systemd/velmix-app.env.example`
- target coordinado: `ops/systemd/velmix-backend.target`
- scheduler service: `ops/systemd/velmix-scheduler.service`
- queue worker service: `ops/systemd/velmix-queue-worker.service`
- queue restart hook: `ops/systemd/velmix-queue-restart.service`
- instalacion de units: `ops/scripts/install-systemd-units.sh`
- inicializacion de shared path: `ops/scripts/bootstrap-shared-path.sh`
- backup readiness: `ops/scripts/check-backup-readiness.sh`
- restore drill: `ops/scripts/run-restore-drill.sh`
- preparacion/promocion de release:
  - `ops/scripts/prepare-release.sh <release-path>`
  - `ops/scripts/promote-release.sh <release-path>`
  - `ops/scripts/rollback-to-previous-release.sh`

### Secuencia controlada

1. Publicar artefacto o release candidato bajo `releases/<timestamp-o-version>`
2. Instalar o actualizar units si el nodo es nuevo o si cambiaron los assets operativos:
   - `ops/scripts/install-systemd-units.sh`
3. Inicializar estructura compartida si el nodo es nuevo:
   - `ops/scripts/bootstrap-shared-path.sh`
4. Validar backup posture antes de preparar el release:
   - `ops/scripts/check-backup-readiness.sh`
5. Preparar el release sin exponer trafico:
   - `ops/scripts/prepare-release.sh /var/www/velmix/releases/<release>`
6. Promover con swap atomico:
   - `ops/scripts/promote-release.sh /var/www/velmix/releases/<release>`
7. Verificar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:preflight --json`
   - `php artisan system:backup-readiness --json --fail-on-warning`
   - `php artisan system:alerts --json`
   - `php artisan system:restore-drill --json`
   - `ops/scripts/run-restore-drill.sh`
   - `php artisan schedule:list`

### Criterios de salida de deploy

- readiness en `ready`
- backup readiness en `ok`
- alertas criticas en cero o conocidas
- scheduler visible y sin comandos faltantes
- outbox y reconcile smoke sin errores

## Smoke post-deploy

Validacion minima:

- listar rutas: `php artisan route:list --except-vendor`
- listar tareas: `php artisan schedule:list`
- dispatch outbox smoke
- reconcile smoke
- backup readiness smoke
- restore drill smoke
- lectura de docs internas `/docs`
- lectura de dashboard diario `/reports/daily`

## Rollback

### Rollback de aplicacion

Condiciones:

- readiness degradado
- scheduler con errores repetitivos
- regresion funcional confirmada

Pasos:

1. detener trafico de mutacion si el incidente es severo
2. interrumpir scheduler actual:
   - `php artisan schedule:interrupt`
3. revertir al release anterior registrado:
   - `ops/scripts/rollback-to-previous-release.sh`
4. revalidar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:preflight --json --fail-on-critical`
   - `php artisan system:preflight --json`
   - `php artisan system:backup-readiness --json`
   - `php artisan system:alerts --json`
   - `php artisan system:restore-drill --json`
   - `php artisan queue:restart`
   - `php artisan schedule:list`

### Rollback de esquema

Politica:

- evitar rollback destructivo automatico
- preferir migracion compensatoria hacia adelante
- solo ejecutar `migrate:rollback --step=N --force` si el cambio es estrictamente reversible y no hay datos nuevos incompatibles

Antes de revertir esquema revisar:

- tablas nuevas con datos ya escritos
- constraints nuevas que ya hayan rechazado datos invalidos
- comandos scheduler corriendo sobre el esquema nuevo
- procesos `billing:dispatch-outbox` o `billing:reconcile-pending` usando columnas recien agregadas

## Continuidad operativa

- `system:alerts --fail-on-critical` debe usarse como gate manual o de pipeline, no dentro del scheduler
- `system:preflight --fail-on-warning` si debe usarse como gate de deploy, porque valida coherencia de plataforma y release
- `system:backup-readiness --fail-on-warning` debe formar parte del gate antes de promover un release en entornos no locales
- el restore drill es no destructivo y su evidencia debe conservarse junto al release o en storage compartido
- el pruning debe comenzar en modo `--pretend` antes de activarse automatico en un entorno nuevo
- conservar evidencia de `X-Request-Id` y logs JSON durante incidentes
- en multi-nodo, habilitar `VELMIX_SCHEDULER_ON_ONE_SERVER=true` solo si existe cache compartido con locks atomicos
- si se usa `systemd`, cargar `/etc/velmix/velmix.env` a partir de `ops/systemd/velmix-app.env.example`
- el target recomendado para restart coordinado es `velmix-backend.target`

## Checklist de cierre

- workflow SQLite en verde
- workflow MySQL en verde
- readiness OK
- alertas criticas en cero o conocidas
- scheduler registrado
- workers reiniciados despues del deploy
- release actual y release previo visibles via symlink `current` y `previous`
- runbooks accesibles desde `/docs`
- backup manifest reciente registrado
- restore drill reciente y legible
