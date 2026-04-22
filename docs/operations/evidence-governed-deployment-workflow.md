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
- despliegue remoto real ejecutado por `ops/scripts/deploy-release-over-ssh.sh`
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

## Variables recomendadas para topologia remota

- `VELMIX_REMOTE_PORT`
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

## Aprobación manual del environment

- `staging` debe tener `required reviewers`
- el workflow `workflow_dispatch` referencia el environment `${target_environment}` y queda pendiente hasta que se apruebe
- la configuracion reproducible puede aplicarse con:

```bash
ops/scripts/configure-github-environment-protection.sh lretuerto/velmix-app staging <reviewer-id>
```

- los secretos siguen siendo obligatorios para el deploy remoto vivo
- la topologia remota no sensible ahora se consume como variables del environment, no como secretos
- `ops/github-environments/staging.variables.env.example` permite bootstrapear la topologia sin exponer ni inventar secretos
- si el bootstrap corre desde Git Bash sobre Windows, `ops/scripts/sync-github-environment-config.sh` desactiva path conversion para preservar rutas Linux como `/var/www/velmix`

## Ejecucion manual recomendada

```powershell
gh workflow run evidence-governed-deploy.yml `
  --ref sprint1/day8-rbac-seeders-smoke `
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
