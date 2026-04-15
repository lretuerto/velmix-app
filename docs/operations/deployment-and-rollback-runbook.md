# Deployment And Rollback Runbook

## Objetivo

Este documento define una secuencia segura de despliegue y reversa para el backend actual de VELMiX.

## Pre-deploy obligatorio

Ejecutar en CI o staging:

```powershell
composer validate --no-check-publish
composer run velmix:qa
composer run velmix:routes
composer run velmix:readiness
composer run velmix:alerts
composer run velmix:prune
composer run velmix:outbox
composer run velmix:reconcile
```

Si existe lane MySQL disponible:

```powershell
composer run velmix:ci:mysql
```

## Deploy recomendado

1. Publicar artefacto o commit versionado.
2. Activar mantenimiento solo si el cambio toca esquema o mutaciones criticas.
3. Aplicar migraciones:
   - `php artisan migrate --force`
4. Limpiar y recalentar caches:
   - `php artisan optimize:clear`
   - `php artisan config:cache`
   - `php artisan route:cache`
5. Reiniciar workers/procesos de aplicacion si aplica.
6. Verificar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:readiness --json`
   - `php artisan system:alerts --json`

## Smoke post-deploy

Validacion minima:

- listar rutas: `php artisan route:list --except-vendor`
- dispatch outbox smoke
- reconcile smoke
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
2. volver al artefacto o release anterior
3. limpiar caches:
   - `php artisan optimize:clear`
4. revalidar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:readiness --json`

### Rollback de esquema

Politica:

- evitar rollback destructivo automatico
- preferir migracion compensatoria hacia adelante
- solo ejecutar `migrate:rollback --step=N --force` si el cambio es estrictamente reversible y no hay datos nuevos incompatibles

Antes de revertir esquema revisar:

- tablas nuevas con datos ya escritos
- constraints nuevas que ya hayan rechazado datos invalidos
- comandos scheduler corriendo sobre el esquema nuevo

## Continuidad operativa

- `system:alerts --fail-on-critical` debe usarse como gate manual o de pipeline, no dentro del scheduler
- el pruning debe comenzar en modo `--pretend` antes de activarse automatico en un entorno nuevo
- conservar evidencia de `X-Request-Id` y logs JSON durante incidentes

## Checklist de cierre

- workflow SQLite en verde
- workflow MySQL en verde
- readiness OK
- alertas criticas en cero o conocidas
- scheduler registrado
- runbooks accesibles desde `/docs`
