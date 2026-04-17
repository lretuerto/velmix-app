<?php

namespace App\Services\Platform;

class PlatformSafetyService
{
    public function summary(): array
    {
        $items = array_values(array_filter([
            $this->debugModeCheck(),
            $this->schedulerLockStoreCheck(),
        ]));

        return [
            'status' => $items === [] ? 'ok' : 'warning',
            'checked_at' => now()->toIso8601String(),
            'items' => $items,
        ];
    }

    private function debugModeCheck(): ?array
    {
        $appEnv = (string) config('app.env', app()->environment());
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
