# Release Cutover Runbook

## Objetivo

Este runbook define como decidir y registrar, de forma conservadora, el cutover final de un release ya promocionable hacia trafico productivo sin perder rollback ni trazabilidad.

## Principios

- no ejecutar cutover sin `release_identifier` explicito
- no ejecutar cutover si `preflight`, alertas, backup, restore drill o promocion del release no estan en verde
- registrar evidencia del cambio de trafico y del rollback disponible
- tratar la falta de decision final como riesgo operativo, no como una formalidad posterior

## Variables requeridas

- `VELMIX_RELEASE_IDENTIFIER=<release-id>`
- `VELMIX_RELEASE_CUTOVER_ENV=production`
- `VELMIX_RELEASE_CUTOVER_REQUIRED_ENVS=production`
- `VELMIX_RELEASE_CUTOVER_STORAGE_PATH=/var/www/velmix/shared/release-cutovers`
- `VELMIX_RELEASE_CUTOVER_HISTORY_PATH=/var/www/velmix/shared/release-cutovers/history`
- `VELMIX_RELEASE_CUTOVER_MANIFEST_FILENAME=latest-release-cutover.json`
- `VELMIX_RELEASE_CUTOVER_MAX_AGE_HOURS=24`

## Gate previo a go-live

Ejecutar:

```powershell
php artisan system:cutover-readiness --json --fail-on-warning
php artisan system:observability-report --json
```

Si se usan wrappers versionados:

```bash
ops/scripts/check-cutover-readiness.sh
```

## Evidencia minima requerida

- evidencia de ventana de cutover o cambio de trafico
- evidencia de rollback ejecutable o reservado para el mismo release
- evidencia de monitoreo o smoke posterior a go-live
- release promotion vigente para el mismo `release_identifier`
- backup fresco y restore drill reciente

## Registro de decision final

Cuando el gate este en verde:

```powershell
php artisan system:record-release-cutover release-2026-04-21-001 https://prod.example.test/evidence/cutover https://prod.example.test/evidence/rollback --monitoring-evidence=https://prod.example.test/evidence/monitoring --operator=release-bot --json
```

Wrapper equivalente:

```bash
ops/scripts/record-release-cutover.sh release-2026-04-21-001 https://prod.example.test/evidence/cutover https://prod.example.test/evidence/rollback https://prod.example.test/evidence/monitoring release-bot
```

## Verificacion posterior

- `php artisan system:cutover-readiness --json`
- `php artisan system:observability-report --json`
- `GET /reports/platform-observability`
- revisar `cutover.ready_for_cutover`, `cutover.decision_recorded` y `cutover.latest_decision.release`

## Criterios de salida

- `system:cutover-readiness` en `ok`
- `ready_for_cutover=true`
- `decision_recorded=true`
- `latest_decision.release` igual al `VELMIX_RELEASE_IDENTIFIER`
- evidencia reciente y legible en `VELMIX_RELEASE_CUTOVER_STORAGE_PATH`

## Rollback de este endurecimiento

1. revertir el commit del paso
2. retirar variables `VELMIX_RELEASE_CUTOVER_*` si quedaron a medio configurar
3. volver al gate previo con `system:promotion-readiness` y `system:observability-report`

## Riesgos residuales

- la decision sigue dependiendo de evidencia externa real de cutover y monitoreo
- el storage de cutover manifests debe existir fuera del nodo y con retencion adecuada
- este gate no reemplaza un control corporativo de cambio; lo complementa con evidencia tecnica verificable
