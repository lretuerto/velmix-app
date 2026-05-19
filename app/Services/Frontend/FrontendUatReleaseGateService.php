<?php

namespace App\Services\Frontend;

class FrontendUatReleaseGateService
{
    public function __construct(
        private readonly FrontendUatReleaseReadinessService $readiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $config = (array) config('velmix.frontend_uat_release_gate', []);
        $enabled = (bool) ($config['enabled'] ?? false);
        $requiredEnvironments = array_values((array) ($config['required_environments'] ?? []));
        $required = in_array($this->currentEnvironment(), $requiredEnvironments, true);
        $freshnessHours = max(1, (int) ($config['freshness_hours'] ?? 24));

        if (! $enabled) {
            $items = $required ? [[
                'severity' => 'warning',
                'code' => 'frontend_uat_release_gate_disabled',
                'message' => 'Frontend UAT release gate is required for this environment but is not enabled.',
                'action' => 'Set VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true and run php artisan frontend:uat-release-readiness --json before release cutover.',
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
                'freshness_hours' => $freshnessHours,
                'readiness' => null,
                'items' => $items,
            ];
        }

        try {
            $readiness = $this->readiness->summary($freshnessHours);
        } catch (\Throwable $exception) {
            $items = [[
                'severity' => 'critical',
                'code' => 'frontend_uat_release_gate_failed',
                'message' => 'Frontend UAT release gate could not complete.',
                'action' => 'Restore UAT artifact readability and rerun php artisan frontend:uat-release-readiness --json.',
                'metric_snapshot' => [
                    'exception' => $exception->getMessage(),
                ],
            ]];

            return [
                'status' => 'critical',
                'checked_at' => now()->toIso8601String(),
                'enabled' => true,
                'required' => $required,
                'required_environments' => $requiredEnvironments,
                'freshness_hours' => $freshnessHours,
                'readiness' => null,
                'items' => $items,
            ];
        }

        $items = [];

        if (($readiness['status'] ?? 'blocked') !== 'ready_for_release') {
            $items[] = [
                'severity' => 'critical',
                'code' => 'frontend_uat_release_not_ready',
                'message' => 'Frontend UAT release readiness is not signed and fresh.',
                'action' => 'Complete visual evidence, run php artisan frontend:uat-visual-evidence-verify --json, then rerun php artisan frontend:uat-release-readiness --json.',
                'metric_snapshot' => [
                    'readiness_status' => $readiness['status'] ?? 'blocked',
                    'blocked_count' => count($readiness['items'] ?? []),
                ],
            ];
        }

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'enabled' => true,
            'required' => $required,
            'required_environments' => $requiredEnvironments,
            'freshness_hours' => $freshnessHours,
            'readiness' => [
                'status' => $readiness['status'] ?? 'unknown',
                'freshness_hours' => $readiness['freshness_hours'] ?? $freshnessHours,
                'artifacts' => $readiness['artifacts'] ?? [],
                'evidence' => $readiness['evidence'] ?? [],
                'item_count' => count($readiness['items'] ?? []),
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

    private function currentEnvironment(): string
    {
        return (string) config('app.env', app()->environment());
    }
}
