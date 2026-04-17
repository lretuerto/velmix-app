# Operational Assets

Este directorio versiona artefactos de operación para acercar el backend de VELMiX a un despliegue reproducible.

Contenido actual:

- `systemd/velmix-app.env.example`: plantilla de environment file para `systemd` y scripts de release
- `systemd/velmix-scheduler.service`: proceso recomendado para `php artisan schedule:work`
- `systemd/velmix-queue-worker.service`: worker persistente recomendado para `php artisan queue:work`
- `systemd/velmix-queue-restart.service`: hook one-shot para reinicio controlado de workers de cola
- `systemd/velmix-backend.target`: grupo de procesos backend para start/stop/restart coordinado
- `scripts/install-systemd-units.sh`: instala y habilita las unidades versionadas en `systemd`
- `scripts/bootstrap-shared-path.sh`: inicializa estructura compartida (`shared`, `releases`, `storage`, `bootstrap/cache`)
- `scripts/prepare-release.sh`: prepara un release candidato sin promoverlo aun
- `scripts/promote-release.sh`: promueve un release preparado con swap atomico de symlink y rollback defensivo
- `scripts/rollback-to-previous-release.sh`: revierte al release anterior registrado
- `scripts/post-deploy.sh`: secuencia segura post-deploy sobre el release activo
- `scripts/post-rollback.sh`: secuencia segura posterior a rollback de aplicación
- `scripts/check-backend-health.sh`: smoke operativo manual sobre readiness, alerts, outbox, reconcile y scheduler
- `scripts/check-backup-readiness.sh`: valida posture de backup antes de una ventana de despliegue
- `scripts/record-backup-success.sh`: registra el manifiesto del ultimo backup exitoso sin tocar datos de negocio
- `scripts/run-restore-drill.sh`: ejecuta un restore drill no destructivo y persiste evidencia

Suposiciones deliberadas:

- el root operativo vive en `/var/www/velmix`
- el release activo vive en `/var/www/velmix/current`
- el release anterior queda referenciado en `/var/www/velmix/previous`
- el estado compartido vive en `/var/www/velmix/shared`
- PHP y Composer se resuelven via `VELMIX_PHP_BIN` y `VELMIX_COMPOSER_BIN` si difieren del default
- si se usa `systemd`, los servicios se parametrizan via `/etc/velmix/velmix.env`

Secuencia recomendada de adopcion:

1. instalar environment file real basado en `systemd/velmix-app.env.example`
2. instalar units con `scripts/install-systemd-units.sh`
3. inicializar estructura compartida con `scripts/bootstrap-shared-path.sh`
4. preparar cada release con `scripts/prepare-release.sh`
5. promover con `scripts/promote-release.sh`
6. revertir con `scripts/rollback-to-previous-release.sh` si la validacion post-promocion falla

Estos archivos siguen siendo plantillas operativas, pero ya modelan una topologia de release reproducible y reversible para reducir configuration drift antes de activarse en producción.
