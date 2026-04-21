## Objetivo

Este runbook describe el workflow manual de GitHub Actions que gobierna un despliegue por evidencia y lo bloquea si cualquier gate operativo queda en `warning` o `critical`.

## Alcance

- entorno objetivo controlado via `workflow_dispatch`
- evidencia versionada para deploy, rollback, backup, restore, promotion, cutover y certificacion operativa
- artefacto descargable por release con todos los JSON y resúmenes del gate

## Workflow versionado

- archivo: `.github/workflows/evidence-governed-deploy.yml`
- nombre visible: `Evidence Governed Deploy`
- environments soportados: `staging`, `production`
- cadena operativa ejecutada por `ops/scripts/run-evidence-governed-deploy.sh`

## Inputs obligatorios

- `target_environment`
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

## Ejecucion manual recomendada

```powershell
gh workflow run evidence-governed-deploy.yml `
  --ref sprint1/day8-rbac-seeders-smoke `
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
3. `system:record-backup`
4. `system:backup-readiness`
5. `system:restore-drill`
6. `system:record-staging-certification`
7. `system:staging-certification`
8. `system:record-release-promotion`
9. `system:promotion-readiness`
10. `system:record-release-cutover`
11. `system:cutover-readiness`
12. `system:record-operational-certification`
13. `system:operational-certification`
14. `system:observability-report`

## Artefacto esperado

El workflow sube un artifact llamado:

- `evidence-governed-deploy-<environment>-<release_identifier>`

Contenido minimo:

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

## Supuesto controlado

Este workflow no sustituye por si solo un deploy real a hosts productivos. Desde este repo gobierna y verifica la cadena sobre un entorno controlado de ejecución; la evidencia de infraestructura viva debe provenir del entorno staging/prod real.
