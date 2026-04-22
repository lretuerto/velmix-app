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
- `scripts/check-staging-certification.sh`: valida la vigencia de la certificacion de staging para el release actual
- `scripts/certify-staging-release.sh`: registra evidencia de deploy, rollback y continuidad para el release certificado
- `scripts/check-promotion-readiness.sh`: valida si el release actual es promocionable desde este entorno
- `scripts/record-release-promotion.sh`: registra la aprobacion operativa del release actual con evidencia de rollback
- `scripts/check-cutover-readiness.sh`: valida si el release actual esta listo para la decision final de go-live
- `scripts/record-release-cutover.sh`: registra la decision final de cutover del release actual
- `scripts/check-operational-certification.sh`: valida si el release actual ya quedo respaldado por evidencia operativa completa
- `scripts/record-operational-certification.sh`: registra deploy, rollback, backup, restore y monitoreo para el release actual
- `scripts/run-evidence-governed-deploy.sh`: ejecuta la cadena completa de evidencia para un release controlado
- `scripts/bootstrap-remote-host-over-ssh.sh`: valida y prepara el host remoto antes de copiar el release por `scp`
- `scripts/deploy-release-over-ssh.sh`: empaqueta el release actual, lo publica por SSH y ejecuta el gate gobernado por evidencia sobre el host remoto
- `scripts/configure-github-environment-protection.sh`: aplica required reviewers sobre un environment de GitHub Actions via `gh api`
- `scripts/check-github-environment-readiness.sh`: audita reviewers, bypass, secrets y variables de un environment antes del primer deploy vivo
- `scripts/check-production-go-no-go.sh`: consolida branch limpia y readiness de `staging`/`production` antes del paso a produccion
- `scripts/sync-github-environment-config.sh`: sincroniza secrets y variables desde un archivo versionable hacia un environment de GitHub
- `github-environments/staging.env.example`: contrato de configuracion para el primer despliegue remoto real de `staging`
- `github-environments/staging.variables.env.example`: bootstrap seguro de solo variables no sensibles para `staging`
- `github-environments/production.env.example`: contrato espejo para el primer despliegue remoto real de `production`
- `github-environments/production.variables.env.example`: bootstrap seguro de solo variables no sensibles para `production`
- `.github/workflows/evidence-governed-deploy.yml`: workflow manual de GitHub Actions que gobierna el cambio por evidencia y sube un artifact del release

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

Bootstrap recomendado para `staging`:

1. aplicar reviewers con `scripts/configure-github-environment-protection.sh`
2. sincronizar primero variables no sensibles con `github-environments/staging.variables.env.example`
3. preparar luego un archivo real a partir de `github-environments/staging.env.example`
4. sincronizar secrets y variables con `scripts/sync-github-environment-config.sh`
5. validar el environment con `scripts/check-github-environment-readiness.sh`
6. cuando existan secrets reales, validar el host remoto con `scripts/bootstrap-remote-host-over-ssh.sh`
7. repetir el bootstrap espejo para `production`
8. ejecutar `scripts/check-production-go-no-go.sh`
9. recien entonces disparar `.github/workflows/evidence-governed-deploy.yml`

Nota operativa:

- si el bootstrap corre desde Git Bash sobre Windows, `scripts/sync-github-environment-config.sh` desactiva path conversion para evitar que rutas Linux terminen convertidas a paths locales de Windows
