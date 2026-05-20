## Objetivo

Este runbook describe el workflow de GitHub Actions que gobierna un despliegue por evidencia y lo bloquea si cualquier gate operativo queda en `warning` o `critical`.

## Alcance

- smoke controlado via `push` sin tocar infraestructura viva
- despliegue real via `workflow_dispatch` hacia `staging` o `production`
- evidencia versionada para deploy, rollback, backup, restore, promotion, cutover y certificacion operativa
- artefacto descargable por release con todos los JSON y resúmenes del gate

## Workflow versionado

- archivo: `.github/workflows/evidence-governed-deploy.yml`
- nombre visible: `Evidence Governed Deploy`
- environments soportados: `staging`, `production`
- cadena operativa ejecutada por `ops/scripts/run-evidence-governed-deploy.sh`
- bootstrap y validacion remota previa ejecutados por `ops/scripts/bootstrap-remote-host-over-ssh.sh`
- el workflow fuerza `FORCE_JAVASCRIPT_ACTIONS_TO_NODE24=true` para evitar deprecaciones del runtime Node 20 en actions JavaScript como `actions/upload-artifact`
- despliegue remoto real ejecutado por `ops/scripts/deploy-release-over-ssh.sh`
- activacion controlada de `systemd` en el host vivo: `ops/scripts/enable-systemd-managed-node.sh`
- cutover controlado de `single-host` a `production`: `ops/scripts/cutover-single-host-production.sh`
- provision inicial del host Ubuntu 24.04: `ops/scripts/provision-ubuntu-node.sh`
- bootstrap de secrets y variables ejecutable por `ops/scripts/sync-github-environment-config.sh`
- readiness del environment auditable por `ops/scripts/check-github-environment-readiness.sh`
- go/no-go consolidado antes de produccion ejecutable por `ops/scripts/check-production-go-no-go.sh`

## Modos de ejecución

- `push`: smoke controlado sobre runner, sin environment protegido
- `workflow_dispatch` + `deployment_strategy=remote_ssh`: despliegue remoto real y gate completo
- `workflow_dispatch` + `deployment_strategy=control_only`: reservado para troubleshooting; no se considera certificacion viva

## Inputs obligatorios

- `target_environment`
- `deployment_strategy`
- `release_identifier`
- `deploy_evidence`
- `rollback_evidence`
- `approval_evidence`
- `cutover_evidence`
- `backup_artifact`
- `restore_evidence`

## Inputs opcionales

- `smoke_evidence`
- `monitoring_evidence`
- `operator`
- `allow_warning`

## Secrets requeridos para despliegue remoto

- `VELMIX_SSH_HOST`
- `VELMIX_SSH_USER`
- `VELMIX_SSH_PRIVATE_KEY`
- `VELMIX_SSH_KNOWN_HOSTS`

El workflow carga esos secrets del environment y los expone en runtime como `VELMIX_REMOTE_HOST` y `VELMIX_REMOTE_USER` para los wrappers SSH. Los nombres que debes configurar en GitHub siguen siendo `VELMIX_SSH_*`.

## Variables recomendadas para topologia remota

- `VELMIX_REMOTE_PORT`
- `VELMIX_REMOTE_TOPOLOGY_ID`
- `VELMIX_REMOTE_TOPOLOGY_MODE`
- `VELMIX_GOVERNANCE_MODE`
- `VELMIX_REMOTE_APP_ROOT`
- `VELMIX_REMOTE_RELEASES_PATH`
- `VELMIX_REMOTE_SHARED_PATH`
- `VELMIX_REMOTE_ENV_FILE`
- `VELMIX_REMOTE_TMP_PATH`
- `VELMIX_REMOTE_USE_SYSTEMD`
- `VELMIX_REMOTE_INSTALL_UNITS`
- `VELMIX_REMOTE_PHP_BIN`
- `VELMIX_REMOTE_COMPOSER_BIN`
- `VELMIX_REMOTE_SYSTEMD_TARGET`
- `VELMIX_REMOTE_QUEUE_RESTART_SERVICE`

Si se activa `VELMIX_REMOTE_USE_SYSTEMD=true`, el nodo remoto debe tener `/etc/velmix/velmix.env` con prioridad real sobre los defaults de las units; el objetivo es que `staging` no herede `APP_ENV=production` ni defaults de cola que no correspondan al entorno activo.
Cuando el nodo ya tenga `/var/www/velmix/shared/.env` validado, el camino mas seguro es sincronizar ese archivo a `/etc/velmix/velmix.env` con:

```bash
VELMIX_SYNC_SYSTEMD_ENV=true \
VELMIX_SYSTEMD_SOURCE_ENV_FILE=/var/www/velmix/shared/.env \
VELMIX_APP_PATH=/var/www/velmix/current \
bash ops/scripts/install-systemd-units.sh
```

Si el paso se ejecuta manualmente como `root` sobre un nodo vivo ya validado, preferir el wrapper controlado:

```bash
VELMIX_APP_PATH=/var/www/velmix/current \
bash ops/scripts/enable-systemd-managed-node.sh
```

Ese wrapper sincroniza el `.env` vivo, instala las units versionadas, ajusta permisos del environment file, habilita `velmix-backend.target` y corre el health check del backend antes de devolver control.

Si la operacion entra en modo pragmatico `single-host` y el mismo nodo debe pasar de `staging` a `production`, preferir:

```bash
VELMIX_APP_PATH=/var/www/velmix/current \
VELMIX_TARGET_APP_URL=https://velmix.gacicorporacion.com \
bash ops/scripts/cutover-single-host-production.sh
```

Ese wrapper respalda el `.env` compartido y `/etc/velmix/velmix.env`, fuerza `APP_ENV=production`, mantiene la postura de staging/promotion evidence, sincroniza `APP_URL`, refuerza las variables de cutover y certificacion operativa para `production`, reinicia `velmix-backend.target` y corre el health check versionado antes del `workflow_dispatch` final.

Si el host de `production` todavia no existe o es un Ubuntu 24.04 recien entregado, el baseline reproducible recomendado es:

```bash
VELMIX_SSH_PORT=22 \
VELMIX_APP_ROOT=/var/www/velmix \
bash ops/scripts/provision-ubuntu-node.sh
```

Ese script instala paquetes base, habilita servicios, crea `deploy`, prepara `ufw` y deja listo el layout compartido que el workflow remoto espera encontrar.

Si el workflow remoto va a reiniciar servicios `systemd` como `deploy`, el host debe conceder `sudo -n` solo para los comandos minimos que usa el pipeline:

```bash
VELMIX_DEPLOY_USER=deploy \
VELMIX_SYSTEMD_TARGET=velmix-backend.target \
VELMIX_QUEUE_RESTART_SERVICE=velmix-queue-restart.service \
bash ops/scripts/install-deploy-systemd-sudoers.sh
```

Sin esa autorizacion minima, `ops/scripts/bootstrap-remote-host-over-ssh.sh` debe bloquear el release con `remote_systemd_control_privileges_missing` antes de intentar la promocion.
El script `ops/scripts/install-deploy-systemd-sudoers.sh` deja esa politica versionada, la valida con `visudo` y evita drift manual entre `staging` y `production`.
Ademas, `staging` y `production` deben declarar `VELMIX_REMOTE_TOPOLOGY_ID` con valores distintos; `ops/scripts/check-production-go-no-go.sh` debe bloquear `production` si ambos entornos comparten el mismo identificador o si `production` no declara uno.
Si se elige un despliegue `single-host`, ambos entornos deben declarar el mismo `VELMIX_REMOTE_TOPOLOGY_ID` y `VELMIX_REMOTE_TOPOLOGY_MODE=single-host`; el gate pasa a `warning` en vez de `blocked`, dejando la excepcion explicitamente trazada.
Si ademas se acepta una operacion `single-operator`, `production` debe declarar `VELMIX_GOVERNANCE_MODE=single-operator`; el gate pasa a `warning` en vez de `blocked` para reviewer unico, self-review y admin bypass, dejando la excepcion de gobernanza explicitamente trazada.
Cuando el workflow corre con `target_environment=production`, `ops/scripts/run-evidence-governed-deploy.sh` ya no regraba certificacion de staging ni aprobacion de promocion; reutiliza la evidencia previa (`staging_summary.json` y `promotion_summary.json`) y solo registra cutover y certificacion operativa sobre el nodo objetivo.

## Bootstrap reproducible del environment

1. aplicar reviewers:

```bash
ops/scripts/configure-github-environment-protection.sh lretuerto/velmix-app staging <reviewer-id>
```

2. sincronizar primero variables no sensibles con:

```bash
ops/scripts/sync-github-environment-config.sh lretuerto/velmix-app staging ops/github-environments/staging.variables.env.example
```

3. preparar un archivo real a partir de `ops/github-environments/staging.env.example` para los secretos
4. sincronizarlo con el mismo script solo cuando ya existan los valores reales
5. auditar el environment:

```bash
ops/scripts/check-github-environment-readiness.sh lretuerto/velmix-app staging
```

6. repetir el bootstrap espejo para `production` con `ops/github-environments/production.env.example`
7. ejecutar el gate consolidado:

```bash
ops/scripts/check-production-go-no-go.sh lretuerto/velmix-app
```

8. solo si el readiness queda `ok` o `warning` controlado, disparar el workflow

Para `production`, el gate consolidado debe tratar como `blocked` estos casos de gobernanza:

- menos de 2 reviewers reales
- `prevent_self_review=false`
- `can_admins_bypass=true`
- el script `ops/scripts/configure-github-environment-protection.sh` ya acepta reviewers separados por coma para dejar esa gobernanza aplicada de forma reproducible
- solo si se declara `VELMIX_GOVERNANCE_MODE=single-operator`, el gate degrada esas condiciones a `warning` controlado y agrega `single_operator_governance_acknowledged`

## Aprobación manual del environment

- `staging` debe tener `required reviewers`
- `production` debe tener al menos 2 reviewers independientes para considerarse `GO` serio
- el workflow `workflow_dispatch` referencia el environment `${target_environment}` y queda pendiente hasta que se apruebe
- la configuracion reproducible puede aplicarse con:

```bash
ops/scripts/configure-github-environment-protection.sh lretuerto/velmix-app staging <reviewer-id>
```

Ejemplo para `production` con dos reviewers:

```bash
VELMIX_PREVENT_SELF_REVIEW=true \
ops/scripts/configure-github-environment-protection.sh \
  lretuerto/velmix-app \
  production \
  "<reviewer-id-1>,<reviewer-id-2>"
```

- los secretos siguen siendo obligatorios para el deploy remoto vivo
- la topologia remota no sensible ahora se consume como variables del environment, no como secretos
- `ops/github-environments/staging.variables.env.example` permite bootstrapear la topologia sin exponer ni inventar secretos
- si el bootstrap corre desde Git Bash sobre Windows, `ops/scripts/sync-github-environment-config.sh` desactiva path conversion para preservar rutas Linux como `/var/www/velmix`

## Ejecucion manual recomendada

```powershell
gh workflow run evidence-governed-deploy.yml `
  --ref main `
  -f deployment_strategy=remote_ssh `
  -f target_environment=staging `
  -f release_identifier=release-2026-04-21-001 `
  -f deploy_evidence=https://staging.example.test/evidence/deploy `
  -f rollback_evidence=https://staging.example.test/evidence/rollback `
  -f approval_evidence=https://staging.example.test/evidence/approve `
  -f cutover_evidence=https://staging.example.test/evidence/cutover `
  -f backup_artifact=s3://velmix-staging/backups/2026-04-21.sql.gz `
  -f restore_evidence=https://staging.example.test/evidence/restore `
  -f smoke_evidence=https://staging.example.test/evidence/smoke `
  -f monitoring_evidence=https://staging.example.test/evidence/monitoring `
  -f operator=release-bot
```

## Gates ejecutados

1. `system:preflight`
2. `system:alerts`
3. `system:backup-readiness`
4. `system:restore-drill`
5. `system:record-staging-certification`
6. `system:staging-certification`
7. `system:record-release-promotion`
8. `system:promotion-readiness`
9. `system:record-release-cutover`
10. `system:cutover-readiness`
11. `system:record-operational-certification`
12. `system:operational-certification`
13. `system:observability-report`

Previamente, el despliegue remoto real:

1. empaqueta el release actual desde GitHub Actions
2. ejecuta `ops/scripts/bootstrap-remote-host-over-ssh.sh` para crear `REMOTE_TMP_PATH` y validar `tar`, `php`, `composer`, `systemctl` y `.env`
3. lo transfiere por `scp` al host remoto
4. ejecuta `ops/scripts/prepare-release.sh`
5. ejecuta `ops/scripts/promote-release.sh`
6. ejecuta `ops/scripts/check-backend-health.sh`
7. ejecuta la cadena gobernada por evidencia en el host remoto
8. recopila el bundle remoto hacia el artifact del workflow

## Artefacto esperado

El workflow sube un artifact llamado:

- `evidence-governed-deploy-<environment>-<release_identifier>`

Contenido minimo:

- `remote-bootstrap.json` para `remote_ssh`
- `preflight.json`
- `alerts.json`
- `backup_record.json`
- `backup_readiness.json`
- `restore_drill.json`
- `staging_record.json`
- `staging_summary.json`
- `promotion_record.json`
- `promotion_summary.json`
- `cutover_record.json`
- `cutover_summary.json`
- `operational_record.json`
- `operational_summary.json`
- `observability.json`
- `schedule.txt`
- `manifest.json`
- `summary.md`

## Criterios de salida

- job de GitHub Actions en verde
- artifact de evidencia disponible
- `operational_summary.json` con `status=ok`
- `observability.json` incluye `promotion`, `cutover` y `operational_certification`
- `release_identifier` consistente en los manifests generados
- para `remote_ssh`, evidencia descargada desde el host remoto objetivo

## Supuesto controlado

Este workflow puede gobernar un deploy remoto real si el environment tiene reviewers y secrets de SSH configurados. Sin esos secretos, el pipeline debe fallar temprano y no debe considerarse evidencia viva.

Cuando ese fail-fast ocurra, `blockers.json` y `summary.md` deben listar los nombres reales de GitHub Environment Secrets (`VELMIX_SSH_HOST`, `VELMIX_SSH_USER`, `VELMIX_SSH_PRIVATE_KEY`, `VELMIX_SSH_KNOWN_HOSTS`) y no solo las variables runtime derivadas.
