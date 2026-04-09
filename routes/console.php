<?php

use App\Services\Billing\OutboxDispatchService;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
