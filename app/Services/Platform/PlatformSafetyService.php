<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Schema;

class PlatformSafetyService
{
    public function __construct(
        private readonly BackupRecoveryService $backupRecovery,
    ) {}

    public function summary(): array
    {
        $backup = $this->backupRecovery->backupReadinessSummary();
        $items = array_values(array_filter([
            $this->debugModeCheck(),
            $this->cacheStoreCheck(),
            $this->schedulerLockStoreCheck(),
            $this->queueConnectionCheck(),
            $this->loggingChannelCheck(),
            ...$this->writablePathChecks(),
            ...($backup['items'] ?? []),
        ]));

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'backup' => $backup,
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array{severity: string}>  $items
     */
    private function resolveStatus(array $items): string
    {
        if ($items === []) {
            return 'ok';
        }

        if (collect($items)->contains(fn (array $item) => $item['severity'] === 'critical')) {
            return 'critical';
        }

        if (collect($items)->contains(fn (array $item) => $item['severity'] === 'warning')) {
            return 'warning';
        }

        return 'info';
    }

    private function debugModeCheck(): ?array
    {
        $appEnv = $this->currentEnvironment();
        $appDebug = (bool) config('app.debug', false);

        if (in_array($appEnv, ['local', 'testing'], true) || ! $appDebug) {
            return null;
        }

        return [
            'severity' => 'warning',
            'code' => 'app_debug_enabled',
            'message' => 'APP_DEBUG is enabled in a non-local environment.',
            'action' => 'Disable APP_DEBUG before serving production-like traffic.',
            'metric_snapshot' => [
                'app_env' => $appEnv,
                'app_debug' => $appDebug,
            ],
        ];
    }

    private function cacheStoreCheck(): ?array
    {
        $defaultStore = (string) config('cache.default', '');
        $stores = (array) config('cache.stores', []);

        if ($defaultStore !== '' && is_array($stores[$defaultStore] ?? null)) {
            return null;
        }

        return [
            'severity' => 'critical',
            'code' => 'cache_store_missing',
            'message' => 'The configured cache store is missing or invalid.',
            'action' => 'Define a valid CACHE_STORE before promoting the release.',
            'metric_snapshot' => [
                'cache_store' => $defaultStore !== '' ? $defaultStore : null,
                'known_stores' => array_keys($stores),
            ],
        ];
    }

    private function schedulerLockStoreCheck(): ?array
    {
        $onOneServer = (bool) config('velmix.scheduler.on_one_server', false);

        if (! $onOneServer) {
            return null;
        }

        $defaultStore = (string) config('cache.default', 'unknown');
        $stores = (array) config('cache.stores', []);
        $storeChain = [];

        $sharedLockStore = $this->isSharedLockStore($defaultStore, $stores, $storeChain);

        if ($sharedLockStore) {
            return null;
        }

        return [
            'severity' => 'warning',
            'code' => 'scheduler_lock_store_not_shared',
            'message' => 'Scheduler onOneServer is enabled without a shared lock-capable cache store.',
            'action' => 'Use database, redis, memcached, or dynamodb cache for scheduler locks, or disable VELMIX_SCHEDULER_ON_ONE_SERVER.',
            'metric_snapshot' => [
                'scheduler_on_one_server' => $onOneServer,
                'cache_store' => $defaultStore,
                'cache_driver' => $stores[$defaultStore]['driver'] ?? null,
                'store_chain' => $storeChain,
            ],
        ];
    }

    private function queueConnectionCheck(): ?array
    {
        $appEnv = $this->currentEnvironment();
        $connectionName = (string) config('queue.default', '');
        $connections = (array) config('queue.connections', []);
        $connection = $connections[$connectionName] ?? null;

        if ($connectionName === '' || ! is_array($connection)) {
            return [
                'severity' => 'critical',
                'code' => 'queue_connection_missing',
                'message' => 'The configured queue connection is missing or invalid.',
                'action' => 'Configure QUEUE_CONNECTION with a defined queue connection before deploying.',
                'metric_snapshot' => [
                    'queue_connection' => $connectionName !== '' ? $connectionName : null,
                    'known_connections' => array_keys($connections),
                ],
            ];
        }

        $driver = (string) ($connection['driver'] ?? '');

        if ($driver === '') {
            return [
                'severity' => 'critical',
                'code' => 'queue_driver_missing',
                'message' => 'The queue connection does not define a driver.',
                'action' => 'Set a valid driver for the configured queue connection.',
                'metric_snapshot' => [
                    'queue_connection' => $connectionName,
                ],
            ];
        }

        if ($driver === 'database') {
            try {
                $missingTables = [];
                $jobsTable = (string) ($connection['table'] ?? 'jobs');
                $batchingTable = (string) config('queue.batching.table', 'job_batches');
                $failedDriver = (string) config('queue.failed.driver', 'database-uuids');
                $failedTable = (string) config('queue.failed.table', 'failed_jobs');

                foreach ([$jobsTable, $batchingTable] as $table) {
                    if ($table !== '' && ! Schema::hasTable($table)) {
                        $missingTables[] = $table;
                    }
                }

                if (in_array($failedDriver, ['database', 'database-uuids'], true) && $failedTable !== '' && ! Schema::hasTable($failedTable)) {
                    $missingTables[] = $failedTable;
                }
            } catch (\Throwable $exception) {
                return [
                    'severity' => 'critical',
                    'code' => 'queue_storage_check_failed',
                    'message' => 'Queue storage could not be inspected for the configured database queue.',
                    'action' => 'Restore database connectivity before promoting the release.',
                    'metric_snapshot' => [
                        'queue_connection' => $connectionName,
                        'queue_driver' => $driver,
                        'exception' => $exception->getMessage(),
                    ],
                ];
            }

            if ($missingTables !== []) {
                return [
                    'severity' => 'critical',
                    'code' => 'queue_storage_not_ready',
                    'message' => 'Queue storage tables are not ready for the configured database queue.',
                    'action' => 'Run migrations and confirm queue tables exist before serving traffic.',
                    'metric_snapshot' => [
                        'queue_connection' => $connectionName,
                        'queue_driver' => $driver,
                        'missing_tables' => array_values(array_unique($missingTables)),
                    ],
                ];
            }
        }

        if (! in_array($appEnv, ['local', 'testing'], true) && in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
            return [
                'severity' => 'warning',
                'code' => 'queue_connection_not_async',
                'message' => 'The configured queue driver is not durable for production-like workloads.',
                'action' => 'Prefer database, redis, sqs, or another asynchronous durable queue driver in non-local environments.',
                'metric_snapshot' => [
                    'queue_connection' => $connectionName,
                    'queue_driver' => $driver,
                ],
            ];
        }

        return null;
    }

    private function loggingChannelCheck(): ?array
    {
        $appEnv = $this->currentEnvironment();
        $defaultChannel = (string) config('logging.default', '');
        $channels = (array) config('logging.channels', []);

        if ($defaultChannel === '' || ! is_array($channels[$defaultChannel] ?? null)) {
            return [
                'severity' => 'critical',
                'code' => 'logging_channel_missing',
                'message' => 'The configured default logging channel is missing or invalid.',
                'action' => 'Set LOG_CHANNEL to a defined logging channel before deploying.',
                'metric_snapshot' => [
                    'log_channel' => $defaultChannel !== '' ? $defaultChannel : null,
                    'known_channels' => array_keys($channels),
                ],
            ];
        }

        $effectiveChannels = $this->resolveEffectiveLoggingChannels($defaultChannel, $channels);
        $missingChannels = array_values(array_filter(
            $effectiveChannels,
            fn (string $channel) => ! is_array($channels[$channel] ?? null)
        ));

        if ($missingChannels !== []) {
            return [
                'severity' => 'critical',
                'code' => 'logging_stack_channel_missing',
                'message' => 'The configured logging stack references undefined channels.',
                'action' => 'Fix LOG_STACK or declare the missing logging channels before deploying.',
                'metric_snapshot' => [
                    'log_channel' => $defaultChannel,
                    'effective_channels' => $effectiveChannels,
                    'missing_channels' => $missingChannels,
                ],
            ];
        }

        if (! in_array($appEnv, ['local', 'testing'], true) && ! $this->hasStructuredLogging($effectiveChannels)) {
            return [
                'severity' => 'warning',
                'code' => 'structured_logging_not_enabled',
                'message' => 'Structured logging is not enabled for the active logging configuration.',
                'action' => 'Include stderr_json or daily_json in the effective logging channels for production-like environments.',
                'metric_snapshot' => [
                    'log_channel' => $defaultChannel,
                    'effective_channels' => $effectiveChannels,
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function writablePathChecks(): array
    {
        $paths = [
            'storage' => storage_path(),
            'storage_logs' => storage_path('logs'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        $items = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path)) {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'writable_path_missing',
                    'message' => sprintf('A required writable path is missing: %s.', $label),
                    'action' => 'Create the path and ensure the application user can write to it before deploying.',
                    'metric_snapshot' => [
                        'path_label' => $label,
                        'path' => $path,
                    ],
                ];

                continue;
            }

            if (! is_writable($path)) {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'writable_path_not_writable',
                    'message' => sprintf('A required writable path is not writable: %s.', $label),
                    'action' => 'Grant write permissions to the application user before promoting the release.',
                    'metric_snapshot' => [
                        'path_label' => $label,
                        'path' => $path,
                    ],
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $channels
     * @return array<int, string>
     */
    private function resolveEffectiveLoggingChannels(string $defaultChannel, array $channels): array
    {
        $defaultDefinition = $channels[$defaultChannel] ?? null;

        if (! is_array($defaultDefinition)) {
            return [];
        }

        if (($defaultDefinition['driver'] ?? null) !== 'stack') {
            return [$defaultChannel];
        }

        $stackChannels = $defaultDefinition['channels'] ?? [];

        if (is_string($stackChannels)) {
            $stackChannels = explode(',', $stackChannels);
        }

        if (! is_array($stackChannels)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($channel) => is_string($channel) ? trim($channel) : null,
            $stackChannels
        )));
    }

    /**
     * @param  array<int, string>  $effectiveChannels
     */
    private function hasStructuredLogging(array $effectiveChannels): bool
    {
        return collect($effectiveChannels)
            ->contains(fn (string $channel) => in_array($channel, ['stderr_json', 'daily_json'], true));
    }

    private function currentEnvironment(): string
    {
        return (string) config('app.env', app()->environment());
    }

    /**
     * @param  array<string, mixed>  $stores
     * @param  array<int, array{name: string, driver: string|null}>  $storeChain
     * @param  array<int, string>  $visited
     */
    private function isSharedLockStore(string $storeName, array $stores, array &$storeChain, array $visited = []): bool
    {
        if ($storeName === '' || in_array($storeName, $visited, true)) {
            return false;
        }

        $visited[] = $storeName;

        $store = $stores[$storeName] ?? null;
        $driver = is_array($store) ? (string) ($store['driver'] ?? '') : '';

        $storeChain[] = [
            'name' => $storeName,
            'driver' => $driver !== '' ? $driver : null,
        ];

        return match ($driver) {
            'database', 'redis', 'memcached', 'dynamodb' => true,
            'failover' => $this->isSharedFailoverStore($store, $stores, $storeChain, $visited),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $stores
     * @param  array<int, array{name: string, driver: string|null}>  $storeChain
     * @param  array<int, string>  $visited
     */
    private function isSharedFailoverStore(mixed $store, array $stores, array &$storeChain, array $visited): bool
    {
        if (! is_array($store)) {
            return false;
        }

        $failoverStores = $store['stores'] ?? null;

        if (! is_array($failoverStores) || $failoverStores === []) {
            return false;
        }

        foreach ($failoverStores as $failoverStoreName) {
            if (! is_string($failoverStoreName) || ! $this->isSharedLockStore($failoverStoreName, $stores, $storeChain, $visited)) {
                return false;
            }
        }

        return true;
    }
}
