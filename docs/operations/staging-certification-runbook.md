# Staging Certification Runbook

## Objetivo

Este runbook define como certificar un release en staging sin romper continuidad, preservando evidencia de deploy, rollback, backup y restore drill antes de promover hacia produccion.

## Alcance

- aplica a releases ya desplegados en staging
- no reemplaza el backup real ni el restore completo sobre infraestructura aislada
- deja evidencia versionada y legible para auditoria tecnica

## Variables requeridas

- `VELMIX_RELEASE_IDENTIFIER=<release-actual>`
- `VELMIX_STAGING_CERTIFICATION_ENV=staging`
- `VELMIX_STAGING_CERTIFICATION_REQUIRED_ENVS=staging,production`
- `VELMIX_STAGING_CERTIFICATION_STORAGE_PATH=/var/www/velmix/shared/staging-certifications`
- `VELMIX_STAGING_CERTIFICATION_HISTORY_PATH=/var/www/velmix/shared/staging-certifications/history`
- `VELMIX_STAGING_CERTIFICATION_MANIFEST_FILENAME=latest-staging-certification.json`
- `VELMIX_STAGING_CERTIFICATION_MAX_AGE_HOURS=168`

## Gate minimo antes de certificar

```powershell
php artisan system:preflight --json --fail-on-warning
php artisan system:backup-readiness --json --fail-on-warning
php artisan system:restore-drill --json --fail-on-warning
php artisan system:alerts --json
php artisan system:observability-report --json
php artisan system:staging-certification --json
```

## Evidencia requerida

- evidencia de deploy exitoso en staging
- evidencia de rollback validado o rehecho sobre el release previo
- evidencia de smoke funcional o tecnico posterior al deploy
- manifiesto del backup mas reciente
- reporte reciente de restore drill no destructivo

## Registro de certificacion

### Via comando

```powershell
php artisan system:record-staging-certification `
  release-2026-04-21-001 `
  https://staging.example.test/evidence/deploy-2026-04-21 `
  https://staging.example.test/evidence/rollback-2026-04-21 `
  --smoke-evidence=https://staging.example.test/evidence/smoke-2026-04-21 `
  --operator=release-bot `
  --json
```

### Via wrapper versionado

```bash
ops/scripts/certify-staging-release.sh \
  release-2026-04-21-001 \
  https://staging.example.test/evidence/deploy-2026-04-21 \
  https://staging.example.test/evidence/rollback-2026-04-21 \
  https://staging.example.test/evidence/smoke-2026-04-21 \
  s3://velmix-staging/backups/latest.sql.gz \
  release-bot
```

## Validacion posterior

```powershell
php artisan system:staging-certification --json --fail-on-warning
php artisan system:observability-report --json
```

O con wrapper versionado:

```bash
ops/scripts/check-staging-certification.sh
```

El snapshot tecnico debe incluir:

- `certification.staging.status=ok`
- `certification.staging.latest_certification.release=<release-actual>`
- `recovery.backup.status=ok`
- `recovery.restore_drill.status=ok`

## Criterios de salida

- release actual certificado en staging
- respaldo reciente y manifest registrado
- restore drill reciente y legible
- rollback probado y documentado
- smoke posterior al deploy sin fallas abiertas

## Rollback del endurecimiento

Si este bloque genera ruido operativo inesperado:

1. revertir el commit correspondiente
2. retirar variables `VELMIX_STAGING_CERTIFICATION_*` del env si quedaron incompletas
3. conservar la evidencia ya capturada para diagnostico
4. volver al gate previo basado en `system:preflight`, `system:backup-readiness` y `system:restore-drill`

## Riesgos residuales

- no valida por si solo la restauracion completa del proveedor de storage remoto
- no reemplaza una prueba real de DR en entorno aislado
- depende de que `deploy_evidence`, `rollback_evidence` y `smoke_evidence` apunten a evidencia duradera y accesible
