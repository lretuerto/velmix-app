<?php

namespace App\Services\Platform;

use App\Services\Frontend\FrontendUatReleaseGateService;

class SystemPreflightService
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly PlatformSafetyService $platformSafety,
        private readonly CashLedgerReadinessService $cashLedger,
        private readonly FrontendUatReleaseGateService $frontendUatReleaseGate,
    ) {}

    public function summary(): array
    {
        $readiness = $this->health->ready(detailed: true);
        $platformSafety = $this->platformSafety->summary();
        $cashLedger = $this->cashLedger->summary();
        $frontendUatReleaseGate = $this->frontendUatReleaseGate->summary();

        $platformStatus = (string) ($platformSafety['status'] ?? 'ok');
        $cashLedgerStatus = (string) ($cashLedger['status'] ?? 'ok');
        $frontendUatReleaseGateStatus = (string) ($frontendUatReleaseGate['status'] ?? 'ok');
        $status = ($readiness['status'] ?? 'ready') !== 'ready'
            ? 'critical'
            : ($platformStatus === 'critical'
                ? 'critical'
                : ($cashLedgerStatus === 'critical'
                    ? 'critical'
                    : ($frontendUatReleaseGateStatus === 'critical'
                        ? 'critical'
                        : ($platformStatus === 'warning'
                            || $cashLedgerStatus === 'warning'
                            || $frontendUatReleaseGateStatus === 'warning'
                                ? 'warning'
                                : 'ok'))));

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
                'cash_ledger' => $cashLedger,
                'frontend_uat_release_gate' => $frontendUatReleaseGate,
            ],
            'items' => array_values(array_merge(
                $platformSafety['items'] ?? [],
                $cashLedger['items'] ?? [],
                $frontendUatReleaseGate['items'] ?? [],
            )),
        ];
    }
}
