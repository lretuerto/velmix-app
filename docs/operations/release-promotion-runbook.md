## Objetivo

Este runbook define como decidir y registrar, de forma conservadora, que un release ya es promocionable desde staging hacia un entorno superior sin romper continuidad ni trazabilidad.

## Principios

- no promover un release sin `release_identifier` explicito
- no promover si `preflight`, alertas criticas, backup, restore drill o certificacion de staging no estan en verde
- registrar una aprobacion operativa asociada al release actual y con evidencia de rollback
- tratar la falta de evidencia como riesgo operativo, no como formalidad opcional

## Variables requeridas

- `VELMIX_RELEASE_IDENTIFIER=<release-id>`
- `VELMIX_RELEASE_PROMOTION_ENV=staging`
- `VELMIX_RELEASE_PROMOTION_REQUIRED_ENVS=staging`
- `VELMIX_RELEASE_PROMOTION_STORAGE_PATH=/var/www/velmix/shared/release-promotions`
- `VELMIX_RELEASE_PROMOTION_HISTORY_PATH=/var/www/velmix/shared/release-promotions/history`
- `VELMIX_RELEASE_PROMOTION_MANIFEST_FILENAME=latest-release-promotion.json`
- `VELMIX_RELEASE_PROMOTION_MAX_AGE_HOURS=72`

## Gate previo a promocion

Ejecutar:

```powershell
php artisan system:promotion-readiness --json --fail-on-warning
php artisan system:observability-report --json
```

Si se usan wrappers versionados:

```bash
ops/scripts/check-promotion-readiness.sh
```

## Evidencia minima requerida

- evidencia de deploy del release en staging
- evidencia de rollback ensayado o validado
- certificacion de staging vigente para el mismo `release_identifier`
- backup fresco y restore drill reciente
- ausencia de alertas operativas criticas abiertas

## Registro de aprobacion operativa

Cuando el gate este en verde:

```powershell
php artisan system:record-release-promotion release-2026-04-21-001 https://staging.example.test/evidence/approve https://staging.example.test/evidence/rollback --operator=release-bot --json
```

Wrapper equivalente:

```bash
ops/scripts/record-release-promotion.sh release-2026-04-21-001 https://staging.example.test/evidence/approve https://staging.example.test/evidence/rollback release-bot
```

## Verificacion posterior

- `php artisan system:promotion-readiness --json`
- `php artisan system:cutover-readiness --json`
- `php artisan system:observability-report --json`
- `GET /reports/platform-observability`
- revisar `promotion.promotable`, `promotion.approval_recorded`, `promotion.latest_approval.release` y luego preparar `cutover`

## Criterios de salida

- `system:promotion-readiness` en `ok`
- `promotable=true`
- `approval_recorded=true`
- `latest_approval.release` igual al `VELMIX_RELEASE_IDENTIFIER`
- evidencia reciente y legible en `VELMIX_RELEASE_PROMOTION_STORAGE_PATH`

## Rollback de este endurecimiento

1. revertir el commit del paso
2. retirar variables `VELMIX_RELEASE_PROMOTION_*` si quedaron a medio configurar
3. volver al gate previo con `system:staging-certification` y `system:observability-report`

## Riesgos residuales

- la aprobacion sigue dependiendo de evidencia externa real de deploy/rollback
- el storage de promotion manifests debe existir fuera del nodo y con retencion adecuada
- este gate no reemplaza un control corporativo de cambio; lo complementa con evidencia tecnica verificable
