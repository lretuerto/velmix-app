# Operational Assets

Este directorio versiona artefactos de operación para acercar el backend de VELMiX a un despliegue reproducible.

Contenido actual:

- `systemd/velmix-scheduler.service`: proceso recomendado para `php artisan schedule:work`
- `systemd/velmix-queue-restart.service`: hook one-shot para reinicio controlado de workers de cola
- `scripts/post-deploy.sh`: secuencia segura post-deploy sobre el release activo
- `scripts/post-rollback.sh`: secuencia segura posterior a rollback de aplicación
- `scripts/check-backend-health.sh`: smoke operativo manual sobre readiness, alerts, outbox, reconcile y scheduler

Suposiciones deliberadas:

- el release activo vive en `/var/www/velmix/current`
- PHP y Composer se resuelven via `VELMIX_PHP_BIN` y `VELMIX_COMPOSER_BIN` si difieren del default
- la rotación de artefactos/symlink se gestiona fuera de estos scripts

Estos archivos son plantillas operativas y deben ajustarse a la topología real antes de activarse en producción.
