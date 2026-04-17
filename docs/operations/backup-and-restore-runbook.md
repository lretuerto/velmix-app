# Backup And Restore Runbook

## Objetivo

Este runbook define una estrategia conservadora para registrar backups exitosos, validar su frescura y ejecutar drills de restauracion sin tocar datos transaccionales en produccion.

## Principios

- no usar restauracion destructiva directa sobre la base primaria
- registrar siempre un manifiesto despues de cada backup exitoso
- ejecutar restore drills contra un path aislado y con evidencia versionada
- tratar la falta de manifiesto fresco como riesgo operativo, no como detalle administrativo

## Variables requeridas

- `VELMIX_BACKUP_ENABLED=true`
- `VELMIX_BACKUP_DRIVER=external` o el identificador operativo del proveedor real
- `VELMIX_BACKUP_STORAGE_PATH=/var/www/velmix/shared/backups`
- `VELMIX_BACKUP_HISTORY_PATH=/var/www/velmix/shared/backups/history`
- `VELMIX_BACKUP_MANIFEST_FILENAME=latest-backup.json`
- `VELMIX_BACKUP_MAX_AGE_HOURS=26`
- `VELMIX_BACKUP_RETENTION_DAYS=14`
- `VELMIX_BACKUP_REQUIRE_ENCRYPTION=true`
- `VELMIX_BACKUP_ENCRYPTION_PASSPHRASE=<secret>`
- `VELMIX_RESTORE_DRILL_PATH=/var/www/velmix/shared/restore-drills`
- `VELMIX_RESTORE_DRILL_MAX_AGE_DAYS=30`

## Verificaciones previas

Ejecutar antes de una ventana de despliegue o antes de confiar en el posture de recuperacion:

```powershell
php artisan system:backup-readiness --json --fail-on-warning
php artisan system:observability-report --json
```

Si la aplicacion corre con scripts operativos versionados:

```bash
ops/scripts/check-backup-readiness.sh
```

## Registro de backup exitoso

El job real de backup puede vivir fuera de Laravel. Una vez completado, registrar el manifiesto:

```powershell
php artisan system:record-backup "s3://velmix-prod/db/2026-04-17T03-15-00Z.sql.gz" --checksum=sha256:abc123 --size=1048576 --driver=managed-snapshot --json
```

Tambien se puede usar el wrapper versionado:

```bash
ops/scripts/record-backup-success.sh "s3://velmix-prod/db/2026-04-17T03-15-00Z.sql.gz" "sha256:abc123" 1048576 managed-snapshot
```

## Restore drill no destructivo

Ejecutar despues de registrar el manifiesto mas reciente:

```powershell
php artisan system:restore-drill --json --fail-on-warning
```

O con wrapper operativo:

```bash
ops/scripts/run-restore-drill.sh
```

El comando genera un reporte en `VELMIX_RESTORE_DRILL_PATH` y no modifica tablas ni datos de negocio.

## Criterios de salida

- `system:backup-readiness` en `ok`
- manifiesto mas reciente dentro de la ventana configurada
- path de backup y restore drill existente y escribible
- passphrase de cifrado configurada en entornos no locales
- restore drill report reciente y legible

## Rollback operacional

Si el endurecimiento de backup/restore genera ruido inesperado:

1. revertir el commit de este paso
2. eliminar variables `VELMIX_BACKUP_*` y `VELMIX_RESTORE_DRILL_*` del env si quedaron incompletas
3. volver a la secuencia previa de deploy y dejar `system:preflight` como gate principal

## Riesgos que siguen fuera del nodo

- accesibilidad real al storage remoto del backup
- validez criptografica del artefacto fuera del manifiesto
- restore completo en entorno aislado de staging o DR

Este runbook reduce riesgo y MTTR, pero no reemplaza una prueba periodica de restauracion sobre infraestructura real.
