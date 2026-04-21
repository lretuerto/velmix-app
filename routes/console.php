<?php

use App\Services\Billing\BillingReconciliationService;
use App\Services\Billing\OutboxDispatchService;
use App\Services\Platform\BackupRecoveryService;
use App\Services\Platform\OperationalDataPruneService;
use App\Services\Platform\ReleaseCutoverService;
use App\Services\Platform\ReleasePromotionService;
use App\Services\Platform\StagingCertificationService;
use App\Services\Platform\SystemAlertNotificationService;
use App\Services\Platform\SystemAlertService;
use App\Services\Platform\SystemHealthService;
use App\Services\Platform\SystemObservabilityReportService;
use App\Services\Platform\SystemPreflightService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:dispatch-outbox {--tenant=} {--limit=20} {--simulate-result=} {--graceful-if-unmigrated}', function (OutboxDispatchService $service) {
    $tenantOption = $this->option('tenant');
    $limit = max(1, (int) $this->option('limit'));
    $outcome = $this->option('simulate-result');
    $outcome = $outcome !== null && $outcome !== '' ? (string) $outcome : null;
    $gracefulIfUnmigrated = (bool) $this->option('graceful-if-unmigrated');
    $missingStorageMessage = 'Outbox tables are not ready. Run php artisan migrate first.';
    $isOutboxStorageNotReady = static function (QueryException|SQLiteDatabaseDoesNotExistException $exception): bool {
        if ($exception instanceof SQLiteDatabaseDoesNotExistException) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        $previous = strtolower($exception->getPrevious()?->getMessage() ?? '');

        foreach ([
            'no such table',
            'base table or view not found',
            'outbox_events',
            'storage not ready',
        ] as $needle) {
            if (str_contains($message, $needle) || ($previous !== '' && str_contains($previous, $needle))) {
                return true;
            }
        }

        return false;
    };

    try {
        $tenantIds = $tenantOption !== null
            ? [(int) $tenantOption]
            : $service->pendingTenantIds();
        if ($tenantIds === []) {
            $this->comment('No pending outbox events.');

            return 0;
        }

        $summary = [
            'tenant_count' => count($tenantIds),
            'total_processed_count' => 0,
            'status_counts' => [
                'processed' => 0,
                'rejected' => 0,
                'failed' => 0,
            ],
            'tenants' => [],
        ];

        foreach ($tenantIds as $tenantId) {
            $tenantSummary = $service->dispatchBatch($tenantId, $limit, $outcome);
            $summary['total_processed_count'] += (int) $tenantSummary['processed_count'];
            $summary['status_counts']['processed'] += (int) ($tenantSummary['status_counts']['processed'] ?? 0);
            $summary['status_counts']['rejected'] += (int) ($tenantSummary['status_counts']['rejected'] ?? 0);
            $summary['status_counts']['failed'] += (int) ($tenantSummary['status_counts']['failed'] ?? 0);
            $summary['tenants'][] = $tenantSummary;
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (QueryException|SQLiteDatabaseDoesNotExistException $exception) {
        if ($gracefulIfUnmigrated && $isOutboxStorageNotReady($exception)) {
            $this->comment($missingStorageMessage);

            return 0;
        }

        $this->error($isOutboxStorageNotReady($exception)
            ? $missingStorageMessage
            : $exception->getMessage());

        return 1;
    }
})->purpose('Process pending billing outbox events by tenant.');

Artisan::command('billing:reconcile-pending {--tenant=} {--limit=20} {--simulate-result=} {--graceful-if-unmigrated}', function (BillingReconciliationService $service) {
    $tenantOption = $this->option('tenant');
    $limit = max(1, (int) $this->option('limit'));
    $outcome = $this->option('simulate-result');
    $outcome = $outcome !== null && $outcome !== '' ? (string) $outcome : null;
    $gracefulIfUnmigrated = (bool) $this->option('graceful-if-unmigrated');
    $missingStorageMessage = 'Billing reconciliation storage is not ready. Run php artisan migrate first.';

    try {
        $tenantIds = $tenantOption !== null
            ? [(int) $tenantOption]
            : DB::table('tenants')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($tenantIds === []) {
            $this->comment('No tenants available for reconciliation.');

            return 0;
        }

        $items = [];

        foreach ($tenantIds as $tenantId) {
            $items[] = $service->reconcilePending($tenantId, null, $limit, $outcome);
        }

        $this->line(json_encode([
            'tenant_count' => count($tenantIds),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    } catch (QueryException|SQLiteDatabaseDoesNotExistException $exception) {
        if ($gracefulIfUnmigrated) {
            $this->comment($missingStorageMessage);

            return 0;
        }

        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Reconcile pending billing documents by tenant.');

Artisan::command('system:readiness {--json}', function (SystemHealthService $service) {
    $result = $service->ready(detailed: true);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('System status: %s', $result['status']));
        $this->line(sprintf('Database ok: %s', $result['checks']['database']['ok'] ? 'yes' : 'no'));
        $this->line(sprintf('Schema ok: %s', $result['checks']['schema']['ok'] ? 'yes' : 'no'));
    }

    return $result['status'] === 'ready' ? 0 : 1;
})->purpose('Check application readiness.');

Artisan::command('system:alerts {--date=} {--json} {--fail-on-critical}', function (SystemAlertService $service) {
    $result = $service->summary($this->option('date') ?: null);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Alert status: %s', $result['status']));
        $this->line(sprintf('Critical: %d', $result['summary']['critical_count']));
        $this->line(sprintf('Warning: %d', $result['summary']['warning_count']));
        $this->line(sprintf('Info: %d', $result['summary']['info_count']));
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Summarize operational alerts across tenants.');

Artisan::command('system:dispatch-alerts {--date=} {--json} {--force} {--fail-on-error}', function (SystemAlertNotificationService $service) {
    $result = $service->dispatch(
        $this->option('date') ?: null,
        (bool) $this->option('force'),
    );

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Alert dispatch status: %s', $result['status']));
        $this->line(sprintf('Dispatched: %d', $result['notification']['dispatched_count'] ?? 0));
        $this->line(sprintf('Suppressed: %d', $result['notification']['suppressed_count'] ?? 0));
        $this->line(sprintf('Failed: %d', $result['notification']['failed_count'] ?? 0));
    }

    if ((bool) $this->option('fail-on-error') && ($result['status'] ?? 'ok') !== 'ok') {
        return 1;
    }

    return 0;
})->purpose('Dispatch operational alerts through configured notification channels.');

Artisan::command('system:preflight {--json} {--fail-on-critical} {--fail-on-warning}', function (SystemPreflightService $service) {
    $result = $service->summary();

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Preflight status: %s', $result['status']));
        $this->line(sprintf('Readiness: %s', $result['checks']['readiness']['status']));
        $this->line(sprintf('Platform safety: %s', $result['checks']['platform_safety']['status']));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Run release preflight checks for readiness and platform safety.');

Artisan::command('system:backup-readiness {--json} {--fail-on-critical} {--fail-on-warning}', function (BackupRecoveryService $service) {
    $result = $service->backupReadinessSummary();

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Backup readiness status: %s', $result['status']));
        $this->line(sprintf('Enabled: %s', ($result['enabled'] ?? false) ? 'yes' : 'no'));
        $this->line(sprintf('Latest manifest: %s', $result['manifest_path'] ?? 'n/a'));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Check backup recording readiness and manifest freshness.');

Artisan::command('system:record-backup {artifact} {--checksum=} {--size=} {--driver=} {--generated-at=} {--json}', function (BackupRecoveryService $service) {
    $size = $this->option('size');
    $result = $service->recordBackup(
        (string) $this->argument('artifact'),
        $this->option('checksum') !== null ? (string) $this->option('checksum') : null,
        $size !== null && $size !== '' ? max(0, (int) $size) : null,
        $this->option('driver') !== null ? (string) $this->option('driver') : null,
        $this->option('generated-at') !== null ? (string) $this->option('generated-at') : null,
    );

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Backup manifest recorded: %s', $result['manifest_path'] ?? 'n/a'));
    }

    return 0;
})->purpose('Record metadata for a successful backup artifact without mutating business data.');

Artisan::command('system:restore-drill {--json} {--fail-on-critical} {--fail-on-warning}', function (BackupRecoveryService $service) {
    $result = $service->restoreDrillSummary();

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Restore drill status: %s', $result['status']));
        $this->line(sprintf('Latest report: %s', $result['report_path'] ?? (($result['latest_drill']['report_path'] ?? 'n/a'))));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Run a non-destructive restore drill and persist the drill report.');

Artisan::command('system:staging-certification {--json} {--fail-on-critical} {--fail-on-warning}', function (StagingCertificationService $service) {
    $result = $service->summary();

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Staging certification status: %s', $result['status']));
        $this->line(sprintf('Required: %s', ($result['required'] ?? false) ? 'yes' : 'no'));
        $this->line(sprintf('Latest manifest: %s', $result['manifest_path'] ?? 'n/a'));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Summarize staging certification evidence and freshness.');

Artisan::command(
    'system:record-staging-certification
        {release}
        {deploy_evidence}
        {rollback_evidence}
        {--smoke-evidence=}
        {--backup-artifact=}
        {--operator=}
        {--notes=}
        {--certified-at=}
        {--allow-warning}
        {--json}',
    function (StagingCertificationService $service) {
        $result = $service->recordCertification(
            (string) $this->argument('release'),
            (string) $this->argument('deploy_evidence'),
            (string) $this->argument('rollback_evidence'),
            $this->option('smoke-evidence') !== null ? (string) $this->option('smoke-evidence') : null,
            $this->option('backup-artifact') !== null ? (string) $this->option('backup-artifact') : null,
            $this->option('operator') !== null ? (string) $this->option('operator') : null,
            $this->option('notes') !== null ? (string) $this->option('notes') : null,
            $this->option('certified-at') !== null ? (string) $this->option('certified-at') : null,
            (bool) $this->option('allow-warning'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (($result['status'] ?? 'blocked') === 'recorded') {
            $this->info(sprintf('Staging certification recorded: %s', $result['manifest_path'] ?? 'n/a'));
        } else {
            $this->warn(sprintf('Staging certification blocked: %s', $result['status'] ?? 'blocked'));
        }

        return ($result['status'] ?? 'blocked') === 'recorded' ? 0 : 1;
    }
)->purpose('Record staging deploy, rollback, and recovery evidence for the current release.');

Artisan::command('system:promotion-readiness {--date=} {--json} {--fail-on-critical} {--fail-on-warning}', function (ReleasePromotionService $service) {
    $result = $service->summary([
        'date' => $this->option('date') ?: null,
    ]);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Release promotion status: %s', $result['status']));
        $this->line(sprintf('Promotable: %s', ($result['promotable'] ?? false) ? 'yes' : 'no'));
        $this->line(sprintf('Approval recorded: %s', ($result['approval_recorded'] ?? false) ? 'yes' : 'no'));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Summarize whether the current release is promotable from this environment.');

Artisan::command(
    'system:record-release-promotion
        {release}
        {approval_evidence}
        {rollback_evidence}
        {--operator=}
        {--notes=}
        {--approved-at=}
        {--date=}
        {--allow-warning}
        {--json}',
    function (ReleasePromotionService $service) {
        $result = $service->recordApproval(
            (string) $this->argument('release'),
            (string) $this->argument('approval_evidence'),
            (string) $this->argument('rollback_evidence'),
            $this->option('operator') !== null ? (string) $this->option('operator') : null,
            $this->option('notes') !== null ? (string) $this->option('notes') : null,
            $this->option('approved-at') !== null ? (string) $this->option('approved-at') : null,
            $this->option('date') !== null ? (string) $this->option('date') : null,
            (bool) $this->option('allow-warning'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (($result['status'] ?? 'blocked') === 'recorded') {
            $this->info(sprintf('Release promotion recorded: %s', $result['manifest_path'] ?? 'n/a'));
        } else {
            $this->warn(sprintf('Release promotion blocked: %s', $result['status'] ?? 'blocked'));
        }

        return ($result['status'] ?? 'blocked') === 'recorded' ? 0 : 1;
    }
)->purpose('Record release promotion approval evidence for the current release.');

Artisan::command('system:cutover-readiness {--date=} {--json} {--fail-on-critical} {--fail-on-warning}', function (ReleaseCutoverService $service) {
    $result = $service->summary([
        'date' => $this->option('date') ?: null,
    ]);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Release cutover status: %s', $result['status']));
        $this->line(sprintf('Ready for cutover: %s', ($result['ready_for_cutover'] ?? false) ? 'yes' : 'no'));
        $this->line(sprintf('Decision recorded: %s', ($result['decision_recorded'] ?? false) ? 'yes' : 'no'));
    }

    if ((bool) $this->option('fail-on-warning') && in_array($result['status'], ['warning', 'critical'], true)) {
        return 1;
    }

    if ((bool) $this->option('fail-on-critical') && $result['status'] === 'critical') {
        return 1;
    }

    return 0;
})->purpose('Summarize whether the current release is ready for the final production cutover.');

Artisan::command(
    'system:record-release-cutover
        {release}
        {cutover_evidence}
        {rollback_evidence}
        {--monitoring-evidence=}
        {--operator=}
        {--notes=}
        {--decided-at=}
        {--date=}
        {--allow-warning}
        {--json}',
    function (ReleaseCutoverService $service) {
        $result = $service->recordDecision(
            (string) $this->argument('release'),
            (string) $this->argument('cutover_evidence'),
            (string) $this->argument('rollback_evidence'),
            $this->option('monitoring-evidence') !== null ? (string) $this->option('monitoring-evidence') : null,
            $this->option('operator') !== null ? (string) $this->option('operator') : null,
            $this->option('notes') !== null ? (string) $this->option('notes') : null,
            $this->option('decided-at') !== null ? (string) $this->option('decided-at') : null,
            $this->option('date') !== null ? (string) $this->option('date') : null,
            (bool) $this->option('allow-warning'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (($result['status'] ?? 'blocked') === 'recorded') {
            $this->info(sprintf('Release cutover recorded: %s', $result['manifest_path'] ?? 'n/a'));
        } else {
            $this->warn(sprintf('Release cutover blocked: %s', $result['status'] ?? 'blocked'));
        }

        return ($result['status'] ?? 'blocked') === 'recorded' ? 0 : 1;
    }
)->purpose('Record the final go-live cutover decision for the current release.');

Artisan::command('system:observability-report {--date=} {--json}', function (SystemObservabilityReportService $service) {
    $result = $service->summary($this->option('date') ?: null);

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Observability status: %s', $result['status']));
        $this->line(sprintf('Preflight: %s', $result['preflight']['status'] ?? 'unknown'));
        $this->line(sprintf('Alerts: %s', $result['alerts']['status'] ?? 'unknown'));
        $this->line(sprintf('Queue: %s', $result['queue']['connection'] ?? 'unknown'));
        $this->line(sprintf('Logging: %s', implode(', ', $result['logging']['effective_channels'] ?? [])));
        $this->line(sprintf('Promotion: %s', $result['promotion']['status'] ?? 'unknown'));
        $this->line(sprintf('Cutover: %s', $result['cutover']['status'] ?? 'unknown'));
    }

    return 0;
})->purpose('Summarize technical observability, platform alerts, and runtime configuration.');

Artisan::command('platform:prune-operational-data {--pretend} {--json}', function (OperationalDataPruneService $service) {
    $result = $service->prune((bool) $this->option('pretend'));

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Operational data prune total: %d', $result['total_pruned_count']));
    }

    return 0;
})->purpose('Prune operational data with conservative retention windows.');

$schedulerConfig = config('velmix.scheduler', []);
$schedulerTimezone = (string) ($schedulerConfig['timezone'] ?? config('app.timezone', 'UTC'));
$schedulerOnOneServer = (bool) ($schedulerConfig['on_one_server'] ?? false);

$applySchedulerConcurrency = static function (Event $event, int $overlapMinutes) use ($schedulerTimezone, $schedulerOnOneServer): Event {
    $event
        ->timezone($schedulerTimezone)
        ->withoutOverlapping(max(1, $overlapMinutes));

    if ($schedulerOnOneServer) {
        $event->onOneServer();
    }

    return $event;
};

$scheduleEveryMinutes = static function (Event $event, int $minutes): Event {
    $minutes = max(1, $minutes);

    return $minutes === 1
        ? $event->everyMinute()
        : $event->cron(sprintf('*/%d * * * *', $minutes));
};

$dispatchEvent = Schedule::command(sprintf(
    'billing:dispatch-outbox --limit=%d --graceful-if-unmigrated',
    max(1, (int) ($schedulerConfig['dispatch_limit'] ?? 20)),
));
$scheduleEveryMinutes($dispatchEvent, (int) ($schedulerConfig['dispatch_every_minutes'] ?? 1));
$applySchedulerConcurrency($dispatchEvent, (int) ($schedulerConfig['dispatch_overlap_minutes'] ?? 10));

$reconcileEvent = Schedule::command(sprintf(
    'billing:reconcile-pending --limit=%d --graceful-if-unmigrated',
    max(1, (int) ($schedulerConfig['reconcile_limit'] ?? 20)),
));
$scheduleEveryMinutes($reconcileEvent, (int) ($schedulerConfig['reconcile_every_minutes'] ?? 5));
$applySchedulerConcurrency($reconcileEvent, (int) ($schedulerConfig['reconcile_overlap_minutes'] ?? 15));

$alertsEvent = Schedule::command('system:alerts');
$scheduleEveryMinutes($alertsEvent, (int) ($schedulerConfig['alerts_every_minutes'] ?? 5));
$applySchedulerConcurrency($alertsEvent, (int) ($schedulerConfig['alerts_overlap_minutes'] ?? 10));

$alertDispatchEvent = Schedule::command('system:dispatch-alerts');
$scheduleEveryMinutes($alertDispatchEvent, (int) ($schedulerConfig['alert_dispatch_every_minutes'] ?? 5));
$applySchedulerConcurrency($alertDispatchEvent, (int) ($schedulerConfig['alert_dispatch_overlap_minutes'] ?? 10));

$pruneEvent = Schedule::command('platform:prune-operational-data');
$pruneEvent->dailyAt((string) ($schedulerConfig['prune_at'] ?? '03:15'));
$applySchedulerConcurrency($pruneEvent, (int) ($schedulerConfig['prune_overlap_minutes'] ?? 180));
