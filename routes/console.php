<?php

use App\Services\Billing\BillingReconciliationService;
use App\Services\Billing\OutboxDispatchService;
use App\Services\Platform\OperationalDataPruneService;
use App\Services\Platform\SystemAlertService;
use App\Services\Platform\SystemHealthService;
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

Artisan::command('platform:prune-operational-data {--pretend} {--json}', function (OperationalDataPruneService $service) {
    $result = $service->prune((bool) $this->option('pretend'));

    if ((bool) $this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        $this->info(sprintf('Operational data prune total: %d', $result['total_pruned_count']));
    }

    return 0;
})->purpose('Prune operational data with conservative retention windows.');

Schedule::command('billing:dispatch-outbox --limit=20 --graceful-if-unmigrated')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('billing:reconcile-pending --limit=20 --graceful-if-unmigrated')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('system:alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('platform:prune-operational-data')
    ->dailyAt('03:15')
    ->withoutOverlapping();
