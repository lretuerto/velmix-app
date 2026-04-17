<?php

namespace App\Services\Platform;

class SystemPreflightService
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly PlatformSafetyService $platformSafety,
    ) {}

    public function summary(): array
    {
        $readiness = $this->health->ready(detailed: true);
        $platformSafety = $this->platformSafety->summary();

        $platformStatus = (string) ($platformSafety['status'] ?? 'ok');
        $status = ($readiness['status'] ?? 'ready') !== 'ready'
            ? 'critical'
            : ($platformStatus === 'critical'
                ? 'critical'
                : ($platformStatus === 'warning' ? 'warning' : 'ok'));

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'request_id' => app()->bound('request_id') ? app('request_id') : null,
            'checks' => [
                'readiness' => [
                    'status' => $readiness['status'] ?? 'unknown',
                    'checks' => $readiness['checks'] ?? [],
                ],
                'platform_safety' => $platformSafety,
            ],
            'items' => $platformSafety['items'] ?? [],
        ];
    }
}
