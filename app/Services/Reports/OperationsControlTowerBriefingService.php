<?php

namespace App\Services\Reports;

use App\Models\OperationsControlTowerSnapshot;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsControlTowerBriefingService
{
    private const MAX_HISTORY_DAYS = 30;

    public function __construct(
        private readonly OperationsControlTowerReportService $controlTowerReport,
        private readonly OperationsControlTowerSnapshotService $snapshotService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $historyDays = 7,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $priorityLimit = 5,
        int $failureLimit = 5,
        int $staleFollowUpDays = 3,
        ?int $snapshotId = null,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertHistoryDays($historyDays);

        $current = $this->controlTowerReport->summary(
            $tenantId,
            $date,
            $billingDays,
            $financeDaysAhead,
            $priorityLimit,
            $failureLimit,
            $staleFollowUpDays,
        );

        $history = $this->controlTowerReport->history(
            $tenantId,
            $current['date'],
            $historyDays,
            $billingDays,
            $financeDaysAhead,
            $priorityLimit,
            $failureLimit,
            $staleFollowUpDays,
        );

        $resolvedSnapshot = $this->resolveSnapshot($tenantId, $snapshotId, $current['date']);
        $snapshotContext = null;

        if ($resolvedSnapshot !== null) {
            $snapshotDetail = $this->snapshotService->detail($tenantId, $resolvedSnapshot->id);
            $requestedWindowsMatchSnapshotWindows = $current['windows'] === $snapshotDetail['windows'];
            $snapshotCompare = $requestedWindowsMatchSnapshotWindows
                ? $this->snapshotService->compare($tenantId, $resolvedSnapshot->id, null, $current['date'])
                : $this->compareSnapshotToCurrentBriefing($tenantId, $snapshotDetail, $current);

            $snapshotContext = [
                'snapshot' => [
                    'id' => $snapshotDetail['id'],
                    'snapshot_date' => $snapshotDetail['snapshot_date'],
                    'label' => $snapshotDetail['label'],
                    'captured_at' => $snapshotDetail['captured_at'],
                    'captured_by' => $snapshotDetail['captured_by'],
                    'detail_path' => $snapshotDetail['detail_path'],
                    'export_path' => $snapshotDetail['export_path'],
                    'compare_path' => $snapshotDetail['compare_path'],
                ],
                'compare' => $snapshotCompare,
                'requested_windows_match_snapshot_windows' => $requestedWindowsMatchSnapshotWindows,
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'date' => $current['date'],
            'windows' => [
                'history_days' => $historyDays,
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'priority_limit' => $priorityLimit,
                'failure_limit' => $failureLimit,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'paths' => $this->briefingPaths(
                $current['date'],
                $historyDays,
                $billingDays,
                $financeDaysAhead,
                $priorityLimit,
                $failureLimit,
                $staleFollowUpDays,
                $snapshotContext['snapshot']['id'] ?? $snapshotId,
            ),
            'executive_summary' => $current['executive_summary'],
            'current' => $current,
            'history' => [
                'history_window' => $history['history_window'],
                'summary' => $history['summary'],
                'timeline' => $history['timeline'],
            ],
            'snapshot_context' => $snapshotContext,
            'highlights' => [
                'top_health_gates' => $this->topHealthGates($current['health_gates']),
                'key_actions' => array_values(array_slice($current['action_center']['recommended_actions'] ?? [], 0, 5)),
                'trend' => [
                    'status_breakdown' => $history['summary']['status_breakdown'],
                    'worst_day' => $history['summary']['worst_day'],
                    'maxima' => $history['summary']['maxima'],
                ],
                'snapshot_drift' => $snapshotContext !== null ? $this->snapshotDrift($snapshotContext['compare']) : null,
                'insights' => $this->insights($current, $history, $snapshotContext),
            ],
        ];
    }

    public function export(
        int $tenantId,
        ?string $date = null,
        int $historyDays = 7,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $priorityLimit = 5,
        int $failureLimit = 5,
        int $staleFollowUpDays = 3,
        ?int $snapshotId = null,
        string $format = 'markdown',
    ): array {
        $summary = $this->summary(
            $tenantId,
            $date,
            $historyDays,
            $billingDays,
            $financeDaysAhead,
            $priorityLimit,
            $failureLimit,
            $staleFollowUpDays,
            $snapshotId,
        );

        $format = strtolower(trim($format));

        if ($format === 'markdown') {
            return [
                'date' => $summary['date'],
                'format' => 'markdown',
                'content' => $this->renderMarkdown($summary),
            ];
        }

        if ($format === 'json') {
            return [
                'date' => $summary['date'],
                'format' => 'json',
                'payload' => $summary,
            ];
        }

        throw new HttpException(422, 'Operations control tower briefing export format is invalid.');
    }

    private function resolveSnapshot(int $tenantId, ?int $snapshotId, string $date): ?OperationsControlTowerSnapshot
    {
        if ($snapshotId !== null) {
            $snapshot = OperationsControlTowerSnapshot::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $snapshotId)
                ->first();

            if ($snapshot === null) {
                throw new HttpException(404, 'Operations control tower snapshot not found.');
            }

            return $snapshot;
        }

        return OperationsControlTowerSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('snapshot_date', '<=', $date)
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->first();
    }

    private function topHealthGates(array $healthGates): array
    {
        $rank = ['critical' => 2, 'warning' => 1, 'ok' => 0];

        return collect($healthGates)
            ->map(fn (array $gate, string $code) => [
                'code' => $code,
                'status' => $gate['status'] ?? 'ok',
                'label' => $gate['label'] ?? $code,
                'reason' => $gate['reason'] ?? null,
                'action' => $gate['action'] ?? null,
                'path' => $gate['path'] ?? null,
            ])
            ->sortByDesc(fn (array $gate) => $rank[$gate['status']] ?? 0)
            ->take(4)
            ->values()
            ->all();
    }

    private function compareSnapshotToCurrentBriefing(int $tenantId, array $snapshotDetail, array $current): array
    {
        $baseSummary = $snapshotDetail['executive_summary'];
        $compareSummary = $current['executive_summary'];

        return [
            'tenant_id' => $tenantId,
            'mode' => 'live',
            'base' => [
                'type' => 'snapshot',
                'snapshot_id' => $snapshotDetail['id'],
                'snapshot_date' => $snapshotDetail['snapshot_date'],
                'label' => $snapshotDetail['label'],
                'captured_at' => $snapshotDetail['captured_at'],
                'captured_by' => $snapshotDetail['captured_by'],
                'path' => $snapshotDetail['detail_path'],
                'export_path' => $snapshotDetail['export_path'],
                'compare_path' => $snapshotDetail['compare_path'],
                'executive_summary' => $baseSummary,
                'health_gates' => $snapshotDetail['payload']['health_gates'] ?? [],
            ],
            'compare' => [
                'type' => 'live',
                'snapshot_id' => null,
                'snapshot_date' => $current['date'],
                'label' => null,
                'captured_at' => null,
                'captured_by' => null,
                'path' => $current['paths']['self'] ?? sprintf('/reports/operations-control-tower?date=%s', $current['date']),
                'export_path' => null,
                'compare_path' => null,
                'executive_summary' => $compareSummary,
                'health_gates' => $current['health_gates'] ?? [],
            ],
            'windows' => [
                'base' => $snapshotDetail['windows'],
                'compare' => $current['windows'],
                'match' => false,
            ],
            'delta' => $this->deltaMetrics($baseSummary, $compareSummary),
            'overall_status_change' => [
                'from' => $baseSummary['overall_status'],
                'to' => $compareSummary['overall_status'],
                'changed' => $baseSummary['overall_status'] !== $compareSummary['overall_status'],
            ],
            'gate_changes' => $this->gateChanges(
                $snapshotDetail['payload']['health_gates'] ?? [],
                $current['health_gates'] ?? [],
            ),
            'movement' => $this->comparisonMovement($baseSummary, $compareSummary),
            'paths' => [
                'self' => sprintf(
                    '/reports/operations-control-tower/snapshots/%d/compare?date=%s',
                    $snapshotDetail['id'],
                    $current['date'],
                ),
                'export_markdown' => sprintf(
                    '/reports/operations-control-tower/snapshots/%d/compare/export?format=markdown&date=%s',
                    $snapshotDetail['id'],
                    $current['date'],
                ),
                'export_json' => sprintf(
                    '/reports/operations-control-tower/snapshots/%d/compare/export?format=json&date=%s',
                    $snapshotDetail['id'],
                    $current['date'],
                ),
            ],
        ];
    }

    private function snapshotDrift(array $compare): array
    {
        return [
            'movement' => $compare['movement'],
            'overall_status_changed' => $compare['overall_status_change']['changed'],
            'billing_failed_backlog_delta' => $compare['delta']['billing_failed_backlog_count'],
            'finance_overdue_total_delta' => $compare['delta']['finance_overdue_total'],
            'operations_open_alert_delta' => $compare['delta']['operations_open_alert_count'],
            'windows_match' => $compare['windows']['match'],
        ];
    }

    private function insights(array $current, array $history, ?array $snapshotContext): array
    {
        $insights = [];

        $overallStatus = $current['executive_summary']['overall_status'];
        $insights[] = sprintf('Overall status for %s is %s.', $current['date'], $overallStatus);

        if (($current['executive_summary']['billing_failed_backlog_count'] ?? 0) > 0) {
            $insights[] = sprintf(
                'Billing failed backlog is currently %d.',
                (int) $current['executive_summary']['billing_failed_backlog_count'],
            );
        }

        if (($current['executive_summary']['finance_broken_promise_count'] ?? 0) > 0) {
            $insights[] = sprintf(
                'Finance has %d broken promises requiring follow-up.',
                (int) $current['executive_summary']['finance_broken_promise_count'],
            );
        }

        if ($snapshotContext !== null) {
            $movement = $snapshotContext['compare']['movement'];
            $insights[] = sprintf(
                'Current briefing is %s versus snapshot %d.',
                $movement,
                (int) $snapshotContext['snapshot']['id'],
            );

            if (! $snapshotContext['compare']['windows']['match']) {
                $insights[] = 'Snapshot comparison uses different windows than the live briefing.';
            }
        } else {
            $insights[] = 'No persisted snapshot is available on or before the requested date.';
        }

        if (($history['summary']['worst_day']['date'] ?? null) !== null) {
            $insights[] = sprintf(
                'Worst day in the selected window was %s.',
                $history['summary']['worst_day']['date'],
            );
        }

        return array_values(array_unique($insights));
    }

    private function deltaMetrics(array $baseSummary, array $compareSummary): array
    {
        return [
            'sales_completed_total' => round(
                (float) ($compareSummary['sales_completed_total'] ?? 0)
                - (float) ($baseSummary['sales_completed_total'] ?? 0),
                2,
            ),
            'collections_total' => round(
                (float) ($compareSummary['collections_total'] ?? 0)
                - (float) ($baseSummary['collections_total'] ?? 0),
                2,
            ),
            'cash_discrepancy_total' => round(
                (float) ($compareSummary['cash_discrepancy_total'] ?? 0)
                - (float) ($baseSummary['cash_discrepancy_total'] ?? 0),
                2,
            ),
            'billing_pending_backlog_count' => (int) ($compareSummary['billing_pending_backlog_count'] ?? 0)
                - (int) ($baseSummary['billing_pending_backlog_count'] ?? 0),
            'billing_failed_backlog_count' => (int) ($compareSummary['billing_failed_backlog_count'] ?? 0)
                - (int) ($baseSummary['billing_failed_backlog_count'] ?? 0),
            'finance_overdue_total' => round(
                (float) ($compareSummary['finance_overdue_total'] ?? 0)
                - (float) ($baseSummary['finance_overdue_total'] ?? 0),
                2,
            ),
            'finance_broken_promise_count' => (int) ($compareSummary['finance_broken_promise_count'] ?? 0)
                - (int) ($baseSummary['finance_broken_promise_count'] ?? 0),
            'operations_open_alert_count' => (int) ($compareSummary['operations_open_alert_count'] ?? 0)
                - (int) ($baseSummary['operations_open_alert_count'] ?? 0),
        ];
    }

    private function gateChanges(array $baseGates, array $compareGates): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($baseGates), array_keys($compareGates))));
        $changes = [];

        foreach ($keys as $key) {
            $from = $baseGates[$key]['status'] ?? 'ok';
            $to = $compareGates[$key]['status'] ?? 'ok';

            $changes[$key] = [
                'from' => $from,
                'to' => $to,
                'changed' => $from !== $to,
            ];
        }

        return $changes;
    }

    private function comparisonMovement(array $baseSummary, array $compareSummary): string
    {
        $baseScore = $this->riskScore($baseSummary);
        $compareScore = $this->riskScore($compareSummary);

        if ($compareScore > $baseScore) {
            return 'worsened';
        }

        if ($compareScore < $baseScore) {
            return 'improved';
        }

        return 'unchanged';
    }

    private function riskScore(array $summary): int
    {
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];

        return (($rank[$summary['overall_status'] ?? 'ok'] ?? 0) * 100000)
            + ((int) ($summary['critical_gate_count'] ?? 0) * 10000)
            + ((int) ($summary['warning_gate_count'] ?? 0) * 1000)
            + ((int) ($summary['billing_failed_backlog_count'] ?? 0) * 100)
            + ((int) ($summary['finance_broken_promise_count'] ?? 0) * 10)
            + (int) ($summary['operations_open_alert_count'] ?? 0);
    }

    private function briefingPaths(
        string $date,
        int $historyDays,
        int $billingDays,
        int $financeDaysAhead,
        int $priorityLimit,
        int $failureLimit,
        int $staleFollowUpDays,
        ?int $snapshotId = null,
    ): array {
        $query = http_build_query([
            'date' => $date,
            'history_days' => $historyDays,
            'billing_days' => $billingDays,
            'finance_days_ahead' => $financeDaysAhead,
            'priority_limit' => $priorityLimit,
            'failure_limit' => $failureLimit,
            'stale_follow_up_days' => $staleFollowUpDays,
            'snapshot_id' => $snapshotId,
        ]);

        return [
            'self' => '/reports/operations-control-tower/briefing?'.$query,
            'export_markdown' => '/reports/operations-control-tower/briefing/export?'.http_build_query([
                'date' => $date,
                'history_days' => $historyDays,
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'priority_limit' => $priorityLimit,
                'failure_limit' => $failureLimit,
                'stale_follow_up_days' => $staleFollowUpDays,
                'snapshot_id' => $snapshotId,
                'format' => 'markdown',
            ]),
            'export_json' => '/reports/operations-control-tower/briefing/export?'.http_build_query([
                'date' => $date,
                'history_days' => $historyDays,
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'priority_limit' => $priorityLimit,
                'failure_limit' => $failureLimit,
                'stale_follow_up_days' => $staleFollowUpDays,
                'snapshot_id' => $snapshotId,
                'format' => 'json',
            ]),
            'control_tower' => '/reports/operations-control-tower?'.http_build_query([
                'date' => $date,
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'priority_limit' => $priorityLimit,
                'failure_limit' => $failureLimit,
                'stale_follow_up_days' => $staleFollowUpDays,
            ]),
        ];
    }

    private function renderMarkdown(array $summary): string
    {
        $lines = [
            '# Operations Control Tower Briefing',
            '',
            sprintf('- Date: %s', $summary['date']),
            sprintf('- Overall status: %s', $summary['executive_summary']['overall_status']),
            sprintf('- Billing failed backlog: %d', (int) $summary['executive_summary']['billing_failed_backlog_count']),
            sprintf('- Finance overdue total: %s', number_format((float) $summary['executive_summary']['finance_overdue_total'], 2, '.', '')),
            sprintf('- Operations open alerts: %d', (int) $summary['executive_summary']['operations_open_alert_count']),
            '',
            '## Top Health Gates',
        ];

        foreach ($summary['highlights']['top_health_gates'] as $gate) {
            $lines[] = sprintf('- %s (%s): %s', $gate['label'], $gate['status'], $gate['reason'] ?? 'No reason.');
        }

        $lines[] = '';
        $lines[] = '## Key Actions';

        if ($summary['highlights']['key_actions'] === []) {
            $lines[] = '- None';
        } else {
            foreach ($summary['highlights']['key_actions'] as $action) {
                $lines[] = sprintf('- %s', $action);
            }
        }

        $lines[] = '';
        $lines[] = '## Trend';
        $lines[] = sprintf(
            '- Status breakdown: ok=%d, warning=%d, critical=%d',
            (int) ($summary['highlights']['trend']['status_breakdown']['ok_count'] ?? 0),
            (int) ($summary['highlights']['trend']['status_breakdown']['warning_count'] ?? 0),
            (int) ($summary['highlights']['trend']['status_breakdown']['critical_count'] ?? 0),
        );

        if (($summary['highlights']['trend']['worst_day']['date'] ?? null) !== null) {
            $lines[] = sprintf('- Worst day: %s', $summary['highlights']['trend']['worst_day']['date']);
        }

        $lines[] = '';
        $lines[] = '## Snapshot Drift';

        if ($summary['snapshot_context'] === null) {
            $lines[] = '- No snapshot available for drift comparison.';
        } else {
            $lines[] = sprintf('- Snapshot ID: %d', (int) $summary['snapshot_context']['snapshot']['id']);
            $lines[] = sprintf('- Snapshot date: %s', $summary['snapshot_context']['snapshot']['snapshot_date']);
            $lines[] = sprintf('- Movement: %s', $summary['highlights']['snapshot_drift']['movement']);
            $lines[] = sprintf('- Billing failed backlog delta: %d', (int) $summary['highlights']['snapshot_drift']['billing_failed_backlog_delta']);
            $lines[] = sprintf('- Finance overdue total delta: %s', number_format((float) $summary['highlights']['snapshot_drift']['finance_overdue_total_delta'], 2, '.', ''));
            $lines[] = sprintf('- Operations open alerts delta: %d', (int) $summary['highlights']['snapshot_drift']['operations_open_alert_delta']);
        }

        $lines[] = '';
        $lines[] = '## Insights';

        foreach ($summary['highlights']['insights'] as $insight) {
            $lines[] = sprintf('- %s', $insight);
        }

        return implode("\n", $lines);
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }
    }

    private function assertHistoryDays(int $historyDays): void
    {
        if ($historyDays < 1 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Operations control tower briefing history window is invalid.');
        }
    }
}
