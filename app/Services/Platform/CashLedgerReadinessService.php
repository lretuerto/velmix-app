<?php

namespace App\Services\Platform;

use App\Services\Cash\CashLedgerAuditService;

class CashLedgerReadinessService
{
    public function __construct(
        private readonly CashLedgerAuditService $audit,
    ) {}

    public function summary(): array
    {
        $config = (array) config('velmix.cash_ledger_audit', []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $requiredEnvironments = array_values((array) ($config['required_environments'] ?? []));
        $required = in_array($this->currentEnvironment(), $requiredEnvironments, true);
        $tenantId = $this->nullablePositiveInt($config['tenant_id'] ?? null);
        $sessionId = $this->nullablePositiveInt($config['session_id'] ?? null);
        $issueLimit = max(1, min((int) ($config['issue_limit'] ?? 200), 1000));

        if (! $enabled) {
            $items = $required ? [[
                'severity' => 'warning',
                'code' => 'cash_ledger_audit_disabled',
                'message' => 'Cash ledger audit is required for this environment but is not enabled.',
                'action' => 'Set VELMIX_CASH_LEDGER_AUDIT_ENABLED=true and run php artisan cash:audit-session-ledger --fail-on-issues --json before cutover.',
                'metric_snapshot' => [
                    'app_env' => $this->currentEnvironment(),
                    'required_environments' => $requiredEnvironments,
                ],
            ]] : [];

            return [
                'status' => $this->resolveStatus($items),
                'checked_at' => now()->toIso8601String(),
                'enabled' => false,
                'required' => $required,
                'required_environments' => $requiredEnvironments,
                'audit' => null,
                'items' => $items,
            ];
        }

        try {
            $audit = $this->audit->audit($tenantId, $sessionId, $issueLimit);
        } catch (\Throwable $exception) {
            $items = [[
                'severity' => 'critical',
                'code' => 'cash_ledger_audit_failed',
                'message' => 'Cash ledger audit could not complete.',
                'action' => 'Restore database/schema readiness and rerun php artisan cash:audit-session-ledger --json.',
                'metric_snapshot' => [
                    'exception' => $exception->getMessage(),
                    'tenant_id' => $tenantId,
                    'session_id' => $sessionId,
                ],
            ]];

            return [
                'status' => 'critical',
                'checked_at' => now()->toIso8601String(),
                'enabled' => true,
                'required' => $required,
                'required_environments' => $requiredEnvironments,
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'audit' => null,
                'items' => $items,
            ];
        }

        $items = [];

        if ((int) ($audit['issue_count'] ?? 0) > 0) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'cash_ledger_audit_issues_detected',
                'message' => 'Cash ledger audit found source coverage or consistency issues.',
                'action' => 'Run php artisan cash:audit-session-ledger --fail-on-issues --json, remediate listed sources, and rerun the audit before release cutover.',
                'metric_snapshot' => [
                    'issue_count' => (int) ($audit['issue_count'] ?? 0),
                    'truncated' => (bool) ($audit['truncated'] ?? false),
                    'checks' => $audit['checks'] ?? [],
                ],
            ];
        }

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'enabled' => true,
            'required' => $required,
            'required_environments' => $requiredEnvironments,
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'audit' => [
                'status' => $audit['status'] ?? 'unknown',
                'tenant_count' => $audit['tenant_count'] ?? 0,
                'issue_count' => $audit['issue_count'] ?? 0,
                'truncated' => $audit['truncated'] ?? false,
                'checks' => $audit['checks'] ?? [],
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function resolveStatus(array $items): string
    {
        if ($items === []) {
            return 'ok';
        }

        if (collect($items)->contains(fn (array $item) => ($item['severity'] ?? null) === 'critical')) {
            return 'critical';
        }

        if (collect($items)->contains(fn (array $item) => ($item['severity'] ?? null) === 'warning')) {
            return 'warning';
        }

        return 'info';
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function currentEnvironment(): string
    {
        return (string) config('app.env', app()->environment());
    }
}
