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

### Secuencia controlada

1. Publicar artefacto o commit versionado
2. Si existe `schedule:work`, interrumpir scheduler antiguo:
   - `php artisan schedule:interrupt`
3. Si el cambio toca esquema o mutaciones criticas, activar mantenimiento controlado
4. Desplegar codigo nuevo
5. Instalar dependencias de produccion si aplica:
   - `composer install --no-dev --prefer-dist --optimize-autoloader`
6. Aplicar migraciones:
   - `php artisan migrate --force`
7. Limpiar y recalentar caches:
   - `php artisan optimize:clear`
   - `php artisan config:cache`
   - `php artisan route:cache`
8. Ejecutar preflight de plataforma:
   - `php artisan system:preflight --json --fail-on-warning`
9. Reiniciar workers/procesos:
   - `php artisan queue:restart`
   - reiniciar servicio de `schedule:work` o supervisor equivalente
10. Verificar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:preflight --json`
   - `php artisan system:alerts --json`
   - `php artisan schedule:list`

### Criterios de salida de deploy

- readiness en `ready`
- alertas criticas en cero o conocidas
- scheduler visible y sin comandos faltantes
- outbox y reconcile smoke sin errores

## Smoke post-deploy

Validacion minima:

- listar rutas: `php artisan route:list --except-vendor`
- listar tareas: `php artisan schedule:list`
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
2. interrumpir scheduler actual:
   - `php artisan schedule:interrupt`
3. volver al artefacto o release anterior
4. limpiar caches:
   - `php artisan optimize:clear`
5. ejecutar preflight de rollback:
   - `php artisan system:preflight --json --fail-on-critical`
6. reiniciar workers:
   - `php artisan queue:restart`
   - reiniciar proceso `schedule:work` o supervisor equivalente
7. revalidar:
   - `GET /health/live`
   - `GET /health/ready`
   - `php artisan system:preflight --json`
   - `php artisan system:alerts --json`
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
- el pruning debe comenzar en modo `--pretend` antes de activarse automatico en un entorno nuevo
- conservar evidencia de `X-Request-Id` y logs JSON durante incidentes
- en multi-nodo, habilitar `VELMIX_SCHEDULER_ON_ONE_SERVER=true` solo si existe cache compartido con locks atomicos

## Checklist de cierre

- workflow SQLite en verde
- workflow MySQL en verde
- readiness OK
- alertas criticas en cero o conocidas
- scheduler registrado
- workers reiniciados despues del deploy
- runbooks accesibles desde `/docs`
