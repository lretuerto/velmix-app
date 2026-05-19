<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemHealthService
{
    private const REQUIRED_TABLES = [
        'users',
        'tenants',
        'permissions',
        'roles',
        'outbox_events',
        'billing_provider_profiles',
    ];

    public function live(): array
    {
        return [
            'status' => 'live',
            'checked_at' => now()->toIso8601String(),
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
        ];
    }

    public function ready(bool $detailed = false): array
    {
        $databaseOk = false;
        $databaseMessage = null;

        try {
            DB::select('select 1 as ok');
            $databaseOk = true;
        } catch (\Throwable $exception) {
            $databaseMessage = $exception->getMessage();
        }

        $tables = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $tables[$table] = Schema::hasTable($table);
        }

        $missingTables = collect($tables)
            ->filter(fn (bool $exists) => $exists === false)
            ->keys()
            ->values()
            ->all();

        $ready = $databaseOk && $missingTables === [];

        $summary = [
            'status' => $ready ? 'ready' : 'degraded',
            'checked_at' => now()->toIso8601String(),
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
            'checks' => [
                'database' => [
                    'ok' => $databaseOk,
                ],
                'schema' => [
                    'ok' => $missingTables === [],
                ],
            ],
        ];

        if (! $detailed) {
            return $summary;
        }

        $summary['checks']['database']['message'] = $databaseMessage;
        $summary['checks']['schema']['required_tables'] = $tables;
        $summary['checks']['schema']['missing_tables'] = $missingTables;

        return $summary;
    }
}
