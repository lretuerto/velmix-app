<?php

namespace App\Services\Platform;

use App\Services\Reports\OperationsControlTowerReportService;
use Illuminate\Support\Facades\DB;

class SystemAlertService
{
    public function __construct(
        private readonly SystemHealthService $health,
        private readonly PlatformSafetyService $platformSafety,
        private readonly OperationsControlTowerReportService $controlTower,
    ) {}

    public function summary(?string $date = null): array
    {
        $alerts = [];
        $readiness = $this->health->ready();

        if (($readiness['status'] ?? 'ready') !== 'ready') {
            $alerts[] = [
                'severity' => 'critical',
                'scope' => 'platform',
                'code' => 'system_not_ready',
                'message' => 'System readiness is not healthy.',
                'action' => 'Review database/schema readiness before processing operational traffic.',
                'path' => null,
                'tenant_id' => null,
                'metric_snapshot' => [
                    'status' => $readiness['status'] ?? 'unknown',
                    'checks' => $readiness['checks'] ?? [],
                ],
            ];
        }

        foreach ($this->platformSafety->summary()['items'] as $item) {
            $alerts[] = [
                'severity' => $item['severity'],
                'scope' => 'platform',
                'code' => $item['code'],
                'message' => $item['message'],
                'action' => $item['action'],
                'path' => '/docs/operations-runbook',
                'tenant_id' => null,
                'metric_snapshot' => $item['metric_snapshot'] ?? [],
            ];
        }

        $tenantIds = DB::table('tenants')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $config = config('velmix.alerts');

        foreach ($tenantIds as $tenantId) {
            $snapshot = $this->controlTower->summary(
                $tenantId,
                $date,
                (int) $config['billing_days'],
                (int) $config['finance_days_ahead'],
                (int) $config['priority_limit'],
                (int) $config['failure_limit'],
                (int) $config['stale_follow_up_days'],
            );

            if ($this->isColdTenant($snapshot)) {
                continue;
            }

            foreach ($snapshot['health_gates'] as $domain => $gate) {
                if (($gate['status'] ?? 'ok') === 'ok') {
                    continue;
                }

                $alerts[] = [
                    'severity' => (string) $gate['status'],
                    'scope' => 'tenant',
                    'tenant_id' => $tenantId,
                    'code' => sprintf('%s_%s', $domain, $gate['status']),
                    'message' => (string) $gate['reason'],
                    'action' => $gate['action'],
                    'path' => $gate['path'],
                    'metric_snapshot' => $gate['metric_snapshot'] ?? [],
                ];
            }
        }

        $status = $this->resolveStatus($alerts);

        return [
            'status' => $status,
            'checked_at' => now()->toIso8601String(),
            'date' => $date ?? now()->toDateString(),
            'summary' => [
                'critical_count' => count(array_filter($alerts, fn (array $alert) => $alert['severity'] === 'critical')),
                'warning_count' => count(array_filter($alerts, fn (array $alert) => $alert['severity'] === 'warning')),
                'info_count' => count(array_filter($alerts, fn (array $alert) => $alert['severity'] === 'info')),
                'tenant_count' => count($tenantIds),
            ],
            'items' => $alerts,
        ];
    }

    private function resolveStatus(array $alerts): string
    {
        if (collect($alerts)->contains(fn (array $alert) => $alert['severity'] === 'critical')) {
            return 'critical';
        }

        if (collect($alerts)->contains(fn (array $alert) => $alert['severity'] === 'warning')) {
            return 'warning';
        }

        if ($alerts !== []) {
            return 'info';
        }

        return 'ok';
    }

    private function isColdTenant(array $snapshot): bool
    {
        $summary = $snapshot['executive_summary'];

        return (float) ($summary['sales_completed_total'] ?? 0) === 0.0
            && (float) ($summary['collections_total'] ?? 0) === 0.0
            && (float) ($summary['cash_discrepancy_total'] ?? 0) === 0.0
            && (int) ($summary['billing_pending_backlog_count'] ?? 0) === 0
            && (int) ($summary['billing_failed_backlog_count'] ?? 0) === 0
            && (float) ($summary['finance_overdue_total'] ?? 0) === 0.0
            && (int) ($summary['finance_broken_promise_count'] ?? 0) === 0
            && (int) ($summary['operations_critical_alert_count'] ?? 0) === 0;
    }
}
