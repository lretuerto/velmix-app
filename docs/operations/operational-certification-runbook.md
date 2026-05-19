# Operational Certification Runbook

## Objetivo

Este runbook define como certificar operativamente un release ya promovido y con cutover decidido, usando evidencia controlada de deploy, rollback, backup y restore sobre el entorno objetivo.

## Principios

- no registrar certificacion operativa sin `release_identifier` explicito
- no certificar si `preflight`, alertas, backup, restore drill, promotion o cutover no estan en verde
- registrar evidencia trazable de deploy real, rollback real, backup usado y restore validado
- tratar la ausencia de esta certificacion como una brecha de continuidad operativa, no como una formalidad documental

## Variables requeridas

- `VELMIX_RELEASE_IDENTIFIER=<release-id>`
- `VELMIX_OPERATIONAL_CERTIFICATION_ENV=production`
- `VELMIX_OPERATIONAL_CERTIFICATION_REQUIRED_ENVS=production`
- `VELMIX_OPERATIONAL_CERTIFICATION_STORAGE_PATH=/var/www/velmix/shared/operational-certifications`
- `VELMIX_OPERATIONAL_CERTIFICATION_HISTORY_PATH=/var/www/velmix/shared/operational-certifications/history`
- `VELMIX_OPERATIONAL_CERTIFICATION_MANIFEST_FILENAME=latest-operational-certification.json`
- `VELMIX_OPERATIONAL_CERTIFICATION_MAX_AGE_HOURS=24`

## Gate previo

Ejecutar:

```powershell
php artisan system:operational-certification --json --fail-on-warning
php artisan system:observability-report --json
```

Si se usan wrappers versionados:

```bash
ops/scripts/check-operational-certification.sh
```

## Evidencia minima requerida

- evidencia de deploy real del release en el entorno objetivo
- evidencia de rollback real o plenamente ejecutable para el mismo release
- identificador del backup real asociado a la ventana de cambio
- evidencia del restore o restore drill validado para ese backup
- evidencia de promotion y cutover vigentes para el mismo `release_identifier`
- evidencia de monitoreo o smoke posterior al cambio, si aplica

## Registro de certificacion operativa

Cuando el gate este en verde:

```powershell
php artisan system:record-operational-certification release-2026-04-21-001 https://prod.example.test/evidence/deploy https://prod.example.test/evidence/rollback s3://velmix-prod/backups/2026-04-21.sql.gz https://prod.example.test/evidence/restore --monitoring-evidence=https://prod.example.test/evidence/monitoring --operator=release-bot --json
```

Wrapper equivalente:

```bash
ops/scripts/record-operational-certification.sh release-2026-04-21-001 https://prod.example.test/evidence/deploy https://prod.example.test/evidence/rollback s3://velmix-prod/backups/2026-04-21.sql.gz https://prod.example.test/evidence/restore https://prod.example.test/evidence/monitoring release-bot
```

## Verificacion posterior

- `php artisan system:operational-certification --json`
- `php artisan system:observability-report --json`
- `GET /reports/platform-observability`
- revisar `operational_certification.operationally_certified`, `operational_certification.certificate_recorded` y `operational_certification.latest_certificate.release`

## Criterios de salida

- `system:operational-certification` en `ok`
- `operationally_certified=true`
- `certificate_recorded=true`
- `latest_certificate.release` igual al `VELMIX_RELEASE_IDENTIFIER`
- evidencia reciente y legible en `VELMIX_OPERATIONAL_CERTIFICATION_STORAGE_PATH`

## Rollback de este endurecimiento

1. revertir el commit del paso
2. retirar variables `VELMIX_OPERATIONAL_CERTIFICATION_*` si quedaron a medio configurar
3. volver al gate previo con `system:cutover-readiness` y `system:observability-report`

## Riesgos residuales

- la certificacion sigue dependiendo de evidencia externa real de deploy, rollback, backup y restore
- el storage de manifests debe existir fuera del nodo y con retencion adecuada
- este gate complementa, pero no reemplaza, un proceso corporativo de change management
