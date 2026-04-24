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
composer run velmix:staging-certification
composer run velmix:promotion-readiness
composer run velmix:cutover-readiness
composer run velmix:operational-certification
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
- activacion segura de nodo `systemd`: `ops/scripts/enable-systemd-managed-node.sh`
- inicializacion de shared path: `ops/scripts/bootstrap-shared-path.sh`
- backup readiness: `ops/scripts/check-backup-readiness.sh`
- restore drill: `ops/scripts/run-restore-drill.sh`
- staging certification check: `ops/scripts/check-staging-certification.sh`
- staging certification record: `ops/scripts/certify-staging-release.sh`
- promotion readiness check: `ops/scripts/check-promotion-readiness.sh`
- release promotion record: `ops/scripts/record-release-promotion.sh`
- cutover readiness check: `ops/scripts/check-cutover-readiness.sh`
- release cutover record: `ops/scripts/record-release-cutover.sh`
- operational certification check: `ops/scripts/check-operational-certification.sh`
- operational certification record: `ops/scripts/record-operational-certification.sh`
- workflow gobernado por evidencia: `.github/workflows/evidence-governed-deploy.yml`
- wrapper de cadena completa: `ops/scripts/run-evidence-governed-deploy.sh`
- preparacion/promocion de release:
  - `ops/scripts/prepare-release.sh <release-path>`
  - `ops/scripts/promote-release.sh <release-path>`
  - `ops/scripts/rollback-to-previous-release.sh`

### Secuencia controlada

1. Publicar artefacto o release candidato bajo `releases/<timestamp-o-version>`
2. Instalar o actualizar units si el nodo es nuevo o si cambiaron los assets operativos:
   - `ops/scripts/install-systemd-units.sh`
   - si el nodo ya tiene un `.env` vivo validado en `shared/.env`, usar:
     - `VELMIX_SYNC_SYSTEMD_ENV=true VELMIX_SYSTEMD_SOURCE_ENV_FILE=/var/www/velmix/shared/.env VELMIX_APP_PATH=/var/www/velmix/current bash ops/scripts/install-systemd-units.sh`
   - para activar `systemd` como `root` en un solo paso controlado y con chequeo de salud integrado, preferir:
     - `VELMIX_APP_PATH=/var/www/velmix/current bash ops/scripts/enable-systemd-managed-node.sh`
   - si el workflow remoto operara como `deploy` y `VELMIX_REMOTE_USE_SYSTEMD=true`, conceder solo `sudo -n` para los comandos minimos de `systemd`:

```bash
VELMIX_DEPLOY_USER=deploy \
VELMIX_SYSTEMD_TARGET=velmix-backend.target \
VELMIX_QUEUE_RESTART_SERVICE=velmix-queue-restart.service \
bash ops/scripts/install-deploy-systemd-sudoers.sh
```
3. Inicializar estructura compartida si el nodo es nuevo:
   - `ops/scripts/bootstrap-shared-path.sh`
4. Validar backup posture antes de preparar el release:
   - `ops/scripts/check-backup-readiness.sh`
5. Preparar el release sin exponer trafico:
   - `ops/scripts/prepare-release.sh /var/www/velmix/releases/<release>`
6. Promover con swap atomico:
   - `ops/scripts/promote-release.sh /var/www/velmix/releases/<release>`
7. Certificar staging para el release promovido:
   - `ops/scripts/check-staging-certification.sh`
   - `ops/scripts/certify-staging-release.sh <release> <deploy-evidence> <rollback-evidence> [smoke-evidence] [backup-artifact] [operator]`
8. Validar si el release ya es promocionable:
   - `ops/scripts/check-promotion-readiness.sh`
   - `ops/scripts/record-release-promotion.sh <release> <approval-evidence> <rollback-evidence> [operator] [notes]`
9. Validar la decision final de go-live:
   - `ops/scripts/check-cutover-readiness.sh`
   - `ops/scripts/record-release-cutover.sh <release> <cutover-evidence> <rollback-evidence> [monitoring-evidence] [operator] [notes]`
10. Certificar operativamente el release ya activo:
   - `ops/scripts/check-operational-certification.sh`
   - `ops/scripts/record-operational-certification.sh <release> <deploy-evidence> <rollback-evidence> <backup-artifact> <restore-evidence> [monitoring-evidence] [operator] [notes]`
11. Si el despliegue se gobierna desde GitHub Actions:
   - disparar `.github/workflows/evidence-governed-deploy.yml`
   - exigir artifact `evidence-governed-deploy-<environment>-<release>`
   - revisar `summary.md`, `operational_summary.json` y `observability.json`
12. Verificar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:preflight --json`
   - `php artisan system:backup-readiness --json --fail-on-warning`
   - `php artisan system:alerts --json`
   - `php artisan system:restore-drill --json`
   - `php artisan system:staging-certification --json --fail-on-warning`
   - `php artisan system:promotion-readiness --json --fail-on-warning`
   - `php artisan system:cutover-readiness --json --fail-on-warning`
   - `php artisan system:operational-certification --json --fail-on-warning`
   - `ops/scripts/run-restore-drill.sh`
   - `php artisan schedule:list`

### Criterios de salida de deploy

- readiness en `ready`
- backup readiness en `ok`
- staging certification en `ok`
- promotion readiness en `ok`
- cutover readiness en `ok`
- operational certification en `ok`
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
- staging certification smoke
- promotion readiness smoke
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
- la certificacion de staging debe registrar evidencia de deploy, rollback y smoke antes de promover a produccion
- la promocion del release debe registrar aprobacion operativa y evidencia de rollback asociada al `release_identifier`
- el cutover final debe registrar evidencia del cambio de trafico, monitoreo post-go-live y rollback asociado al `release_identifier`
- la certificacion operativa debe registrar deploy real, rollback real, backup utilizado y restore validado para el mismo `release_identifier`
- el workflow `Evidence Governed Deploy` debe tratarse como gate obligatorio de cambio cuando el release se gobierne desde GitHub Actions
- `ops/scripts/check-production-go-no-go.sh` debe bloquear `production` si hay menos de 2 reviewers reales, si existe self-review o si el environment permite bypass administrativo
- `ops/scripts/configure-github-environment-protection.sh` admite reviewers separados por coma para versionar la proteccion de `production` con al menos dos aprobadores
- el pruning debe comenzar en modo `--pretend` antes de activarse automatico en un entorno nuevo
- conservar evidencia de `X-Request-Id` y logs JSON durante incidentes
- en multi-nodo, habilitar `VELMIX_SCHEDULER_ON_ONE_SERVER=true` solo si existe cache compartido con locks atomicos
- si se usa `systemd`, cargar `/etc/velmix/velmix.env` a partir de `ops/systemd/velmix-app.env.example`
- las units versionadas deben cargar `EnvironmentFile` despues de sus defaults para que `APP_ENV`, cola y scheduler puedan sobreescribirse por entorno sin forzar `production`
- si ya existe `shared/.env` validado en el nodo, preferir sincronizarlo a `/etc/velmix/velmix.env` con `VELMIX_SYNC_SYSTEMD_ENV=true` antes de habilitar `velmix-backend.target`
- si el paso lo ejecuta `root` manualmente, `ops/scripts/enable-systemd-managed-node.sh` reduce el riesgo humano porque sincroniza el `.env`, ajusta permisos y valida el target junto con scheduler y worker
- si el deploy remoto usa `systemd` con un usuario no root, el host debe conceder `sudo -n` solo para `daemon-reload`, `restart velmix-backend.target`, `start velmix-queue-restart.service` y `status velmix-backend.target`; sin eso el bootstrap remoto debe bloquear antes de promover el release
- `ops/scripts/install-deploy-systemd-sudoers.sh` permite versionar esa politica minima y validarla con `visudo` antes de escribir `/etc/sudoers.d/velmix-deploy-systemd`
- `staging` y `production` deben declarar `VELMIX_REMOTE_TOPOLOGY_ID` distinto para impedir que el gate de produccion apruebe accidentalmente una topologia compartida
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
- staging certification reciente y asociada al release actual
- release promotion reciente y asociada al release actual
- release cutover reciente y asociado al release actual
- operational certification reciente y asociada al release actual
