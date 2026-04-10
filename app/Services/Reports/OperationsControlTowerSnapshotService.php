<?php

namespace App\Services\Reports;

use App\Models\OperationsControlTowerSnapshot;
use App\Services\Audit\TenantActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsControlTowerSnapshotService
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly OperationsControlTowerReportService $controlTowerReport,
        private readonly TenantActivityLogService $activityLog,
    ) {
    }

    public function create(
        int $tenantId,
        ?int $userId,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $priorityLimit = 5,
        int $failureLimit = 5,
        int $staleFollowUpDays = 3,
        ?string $label = null,
    ): array {
        $this->assertTenantId($tenantId);
        $label = $this->normalizeLabel($label);

        $snapshot = $this->controlTowerReport->summary(
            $tenantId,
            $date,
            $billingDays,
            $financeDaysAhead,
            $priorityLimit,
            $failureLimit,
            $staleFollowUpDays,
        );

        $row = OperationsControlTowerSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'snapshot_date' => $snapshot['date'],
            'label' => $label,
            'overall_status' => $snapshot['executive_summary']['overall_status'],
            'critical_gate_count' => $snapshot['executive_summary']['critical_gate_count'],
            'warning_gate_count' => $snapshot['executive_summary']['warning_gate_count'],
            'sales_completed_total' => $snapshot['executive_summary']['sales_completed_total'],
            'collections_total' => $snapshot['executive_summary']['collections_total'],
            'cash_discrepancy_total' => $snapshot['executive_summary']['cash_discrepancy_total'],
            'billing_pending_backlog_count' => $snapshot['executive_summary']['billing_pending_backlog_count'],
            'billing_failed_backlog_count' => $snapshot['executive_summary']['billing_failed_backlog_count'],
            'finance_overdue_total' => $snapshot['executive_summary']['finance_overdue_total'],
            'finance_broken_promise_count' => $snapshot['executive_summary']['finance_broken_promise_count'],
            'operations_open_alert_count' => $snapshot['executive_summary']['operations_open_alert_count'],
            'operations_critical_alert_count' => $snapshot['executive_summary']['operations_critical_alert_count'],
            'payload' => $snapshot,
        ]);

        $this->activityLog->record(
            $tenantId,
            $userId,
            'reports',
            'operations_control_tower.snapshot.created',
            'operations_control_tower_snapshot',
            $row->id,
            'Se capturo snapshot del control tower.',
            [
                'snapshot_id' => $row->id,
                'snapshot_date' => $snapshot['date'],
                'overall_status' => $snapshot['executive_summary']['overall_status'],
                'label' => $label,
            ],
        );

        return $this->detail($tenantId, $row->id);
    }

    public function index(
        int $tenantId,
        int $limit = 20,
        ?string $status = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $label = null,
    ): array
    {
        $this->assertTenantId($tenantId);
        $this->assertLimit($limit);
        $this->assertStatus($status);

        $label = $this->normalizeSearchLabel($label);
        $query = $this->filteredIndexQuery($tenantId, $status, $fromDate, $toDate, $label);
        $statusCounts = (clone $query)
            ->select('overall_status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('overall_status')
            ->pluck('aggregate', 'overall_status');
        $totalCount = (clone $query)->count();

        $items = (clone $query)
            ->leftJoin('users', 'users.id', '=', 'operations_control_tower_snapshots.user_id')
            ->orderByDesc('operations_control_tower_snapshots.snapshot_date')
            ->orderByDesc('operations_control_tower_snapshots.id')
            ->limit($limit)
            ->get([
                'operations_control_tower_snapshots.*',
                'users.name as user_name',
            ])
            ->map(fn (object $snapshot) => $this->formatListItem($snapshot))
            ->all();

        return [
            'tenant_id' => $tenantId,
            'filters' => [
                'status' => $status,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'label' => $label,
            ],
            'summary' => [
                'total_count' => $totalCount,
                'returned_count' => count($items),
                'critical_count' => (int) ($statusCounts['critical'] ?? 0),
                'warning_count' => (int) ($statusCounts['warning'] ?? 0),
                'ok_count' => (int) ($statusCounts['ok'] ?? 0),
            ],
            'items' => $items,
        ];
    }

    public function detail(int $tenantId, int $snapshotId): array
    {
        $this->assertTenantId($tenantId);

        $snapshot = DB::table('operations_control_tower_snapshots')
            ->leftJoin('users', 'users.id', '=', 'operations_control_tower_snapshots.user_id')
            ->where('operations_control_tower_snapshots.tenant_id', $tenantId)
            ->where('operations_control_tower_snapshots.id', $snapshotId)
            ->first([
                'operations_control_tower_snapshots.*',
                'users.name as user_name',
            ]);

        if ($snapshot === null) {
            throw new HttpException(404, 'Operations control tower snapshot not found.');
        }

        return $this->formatDetailItem($snapshot);
    }

    public function export(int $tenantId, int $snapshotId, string $format = 'markdown'): array
    {
        $detail = $this->detail($tenantId, $snapshotId);
        $format = strtolower(trim($format));

        if ($format === 'markdown') {
            return [
                'snapshot_id' => $detail['id'],
                'format' => 'markdown',
                'content' => $this->renderMarkdown($detail),
            ];
        }

        if ($format === 'json') {
            return [
                'snapshot_id' => $detail['id'],
                'format' => 'json',
                'payload' => $detail['payload'],
            ];
        }

        throw new HttpException(422, 'Snapshot export format is invalid.');
    }

    public function compare(
        int $tenantId,
        int $snapshotId,
        ?int $againstSnapshotId = null,
        ?string $date = null,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertComparisonTarget($againstSnapshotId, $date);

        $base = $this->detail($tenantId, $snapshotId);

        if ($againstSnapshotId !== null) {
            $compare = $this->detail($tenantId, $againstSnapshotId);

            return $this->buildComparison(
                tenantId: $tenantId,
                base: $base,
                compareMeta: [
                    'type' => 'snapshot',
                    'snapshot_id' => $compare['id'],
                    'snapshot_date' => $compare['snapshot_date'],
                    'label' => $compare['label'],
                    'captured_at' => $compare['captured_at'],
                    'captured_by' => $compare['captured_by'],
                    'path' => $compare['detail_path'],
                    'export_path' => $compare['export_path'],
                    'compare_path' => $compare['compare_path'],
                    'executive_summary' => $compare['executive_summary'],
                    'health_gates' => $compare['payload']['health_gates'] ?? [],
                ],
                baseWindows: $base['windows'],
                compareWindows: $compare['windows'],
                againstSnapshotId: $againstSnapshotId,
            );
        }

        $baseWindows = $base['windows'];
        $compareDate = $date ?? CarbonImmutable::now()->toDateString();
        $comparePayload = $this->controlTowerReport->summary(
            $tenantId,
            $compareDate,
            (int) ($baseWindows['billing_days'] ?? 7),
            (int) ($baseWindows['finance_days_ahead'] ?? 7),
            (int) ($baseWindows['priority_limit'] ?? 5),
            (int) ($baseWindows['failure_limit'] ?? 5),
            (int) ($baseWindows['stale_follow_up_days'] ?? 3),
        );

        return $this->buildComparison(
            tenantId: $tenantId,
            base: $base,
            compareMeta: [
                'type' => 'live',
                'snapshot_id' => null,
                'snapshot_date' => $comparePayload['date'],
                'label' => null,
                'captured_at' => null,
                'captured_by' => null,
                'path' => sprintf('/reports/operations-control-tower?date=%s', $comparePayload['date']),
                'export_path' => null,
                'compare_path' => null,
                'executive_summary' => $comparePayload['executive_summary'],
                'health_gates' => $comparePayload['health_gates'],
            ],
            baseWindows: $baseWindows,
            compareWindows: $comparePayload['windows'],
            compareDate: $comparePayload['date'],
        );
    }

    public function exportComparison(
        int $tenantId,
        int $snapshotId,
        ?int $againstSnapshotId = null,
        ?string $date = null,
        string $format = 'markdown',
    ): array {
        $comparison = $this->compare($tenantId, $snapshotId, $againstSnapshotId, $date);
        $format = strtolower(trim($format));

        if ($format === 'markdown') {
            return [
                'snapshot_id' => $snapshotId,
                'format' => 'markdown',
                'content' => $this->renderComparisonMarkdown($comparison),
            ];
        }

        if ($format === 'json') {
            return [
                'snapshot_id' => $snapshotId,
                'format' => 'json',
                'payload' => $comparison,
            ];
        }

        throw new HttpException(422, 'Snapshot comparison export format is invalid.');
    }

    private function formatListItem(object $snapshot): array
    {
        return [
            'id' => (int) $snapshot->id,
            'snapshot_date' => CarbonImmutable::parse((string) $snapshot->snapshot_date)->toDateString(),
            'label' => $snapshot->label,
            'captured_at' => $snapshot->created_at,
            'captured_by' => $snapshot->user_id !== null ? [
                'id' => (int) $snapshot->user_id,
                'name' => $snapshot->user_name,
            ] : null,
            'executive_summary' => [
                'overall_status' => $snapshot->overall_status,
                'critical_gate_count' => (int) $snapshot->critical_gate_count,
                'warning_gate_count' => (int) $snapshot->warning_gate_count,
                'sales_completed_total' => round((float) $snapshot->sales_completed_total, 2),
                'collections_total' => round((float) $snapshot->collections_total, 2),
                'cash_discrepancy_total' => round((float) $snapshot->cash_discrepancy_total, 2),
                'billing_pending_backlog_count' => (int) $snapshot->billing_pending_backlog_count,
                'billing_failed_backlog_count' => (int) $snapshot->billing_failed_backlog_count,
                'finance_overdue_total' => round((float) $snapshot->finance_overdue_total, 2),
                'finance_broken_promise_count' => (int) $snapshot->finance_broken_promise_count,
                'operations_open_alert_count' => (int) $snapshot->operations_open_alert_count,
                'operations_critical_alert_count' => (int) $snapshot->operations_critical_alert_count,
            ],
            'detail_path' => sprintf('/reports/operations-control-tower/snapshots/%d', $snapshot->id),
            'export_path' => sprintf('/reports/operations-control-tower/snapshots/%d/export?format=markdown', $snapshot->id),
            'compare_path' => sprintf('/reports/operations-control-tower/snapshots/%d/compare', $snapshot->id),
            'compare_export_path' => sprintf('/reports/operations-control-tower/snapshots/%d/compare/export?format=markdown', $snapshot->id),
        ];
    }

    private function formatDetailItem(object $snapshot): array
    {
        $payload = json_decode((string) $snapshot->payload, true, 512, JSON_THROW_ON_ERROR);

        return array_merge($this->formatListItem($snapshot), [
            'tenant_id' => (int) $snapshot->tenant_id,
            'windows' => $payload['windows'] ?? [],
            'payload' => $payload,
        ]);
    }

    private function renderMarkdown(array $detail): string
    {
        $payload = $detail['payload'];
        $healthGates = $payload['health_gates'] ?? [];
        $recommendedActions = $payload['action_center']['recommended_actions'] ?? [];

        $lines = [
            '# Operations Control Tower Snapshot',
            '',
            sprintf('- Snapshot ID: %d', $detail['id']),
            sprintf('- Snapshot date: %s', $detail['snapshot_date']),
            sprintf('- Captured at: %s', (string) $detail['captured_at']),
            sprintf('- Overall status: %s', $detail['executive_summary']['overall_status']),
        ];

        if ($detail['label'] !== null) {
            $lines[] = sprintf('- Label: %s', $detail['label']);
        }

        if ($detail['captured_by'] !== null) {
            $lines[] = sprintf('- Captured by: %s', $detail['captured_by']['name']);
        }

        $lines = array_merge($lines, [
            '',
            '## Executive Summary',
            sprintf('- Sales completed total: %s', number_format((float) $detail['executive_summary']['sales_completed_total'], 2, '.', '')),
            sprintf('- Collections total: %s', number_format((float) $detail['executive_summary']['collections_total'], 2, '.', '')),
            sprintf('- Cash discrepancy total: %s', number_format((float) $detail['executive_summary']['cash_discrepancy_total'], 2, '.', '')),
            sprintf('- Billing pending backlog: %d', (int) $detail['executive_summary']['billing_pending_backlog_count']),
            sprintf('- Billing failed backlog: %d', (int) $detail['executive_summary']['billing_failed_backlog_count']),
            sprintf('- Finance overdue total: %s', number_format((float) $detail['executive_summary']['finance_overdue_total'], 2, '.', '')),
            sprintf('- Finance broken promises: %d', (int) $detail['executive_summary']['finance_broken_promise_count']),
            sprintf('- Operations open alerts: %d', (int) $detail['executive_summary']['operations_open_alert_count']),
            '',
            '## Health Gates',
        ]);

        foreach ($healthGates as $name => $gate) {
            $lines[] = sprintf('- %s: %s', $name, $gate['status'] ?? 'ok');
        }

        $lines[] = '';
        $lines[] = '## Recommended Actions';

        if ($recommendedActions === []) {
            $lines[] = '- None';
        } else {
            foreach ($recommendedActions as $action) {
                $lines[] = sprintf('- %s', $action);
            }
        }

        return implode("\n", $lines);
    }

    private function buildComparison(
        int $tenantId,
        array $base,
        array $compareMeta,
        array $baseWindows,
        array $compareWindows,
        ?int $againstSnapshotId = null,
        ?string $compareDate = null,
    ): array {
        $baseSummary = $base['executive_summary'];
        $compareSummary = $compareMeta['executive_summary'];

        return [
            'tenant_id' => $tenantId,
            'mode' => $compareMeta['type'],
            'base' => [
                'type' => 'snapshot',
                'snapshot_id' => $base['id'],
                'snapshot_date' => $base['snapshot_date'],
                'label' => $base['label'],
                'captured_at' => $base['captured_at'],
                'captured_by' => $base['captured_by'],
                'path' => $base['detail_path'],
                'export_path' => $base['export_path'],
                'compare_path' => $base['compare_path'],
                'executive_summary' => $baseSummary,
                'health_gates' => $base['payload']['health_gates'] ?? [],
            ],
            'compare' => $compareMeta,
            'windows' => [
                'base' => $baseWindows,
                'compare' => $compareWindows,
                'match' => $baseWindows === $compareWindows,
            ],
            'delta' => $this->deltaMetrics($baseSummary, $compareSummary),
            'overall_status_change' => [
                'from' => $baseSummary['overall_status'],
                'to' => $compareSummary['overall_status'],
                'changed' => $baseSummary['overall_status'] !== $compareSummary['overall_status'],
            ],
            'gate_changes' => $this->gateChanges(
                $base['payload']['health_gates'] ?? [],
                $compareMeta['health_gates'] ?? [],
            ),
            'movement' => $this->comparisonMovement($baseSummary, $compareSummary),
            'paths' => [
                'self' => $this->comparisonPath($base['id'], $againstSnapshotId, $compareDate),
                'export_markdown' => $this->comparisonExportPath($base['id'], $againstSnapshotId, $compareDate, 'markdown'),
                'export_json' => $this->comparisonExportPath($base['id'], $againstSnapshotId, $compareDate, 'json'),
            ],
        ];
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

    private function comparisonPath(int $snapshotId, ?int $againstSnapshotId = null, ?string $date = null): string
    {
        if ($againstSnapshotId !== null) {
            return sprintf(
                '/reports/operations-control-tower/snapshots/%d/compare?against_snapshot=%d',
                $snapshotId,
                $againstSnapshotId,
            );
        }

        if ($date !== null) {
            return sprintf(
                '/reports/operations-control-tower/snapshots/%d/compare?date=%s',
                $snapshotId,
                $date,
            );
        }

        return sprintf('/reports/operations-control-tower/snapshots/%d/compare', $snapshotId);
    }

    private function comparisonExportPath(
        int $snapshotId,
        ?int $againstSnapshotId = null,
        ?string $date = null,
        string $format = 'markdown',
    ): string {
        $base = sprintf('/reports/operations-control-tower/snapshots/%d/compare/export?format=%s', $snapshotId, $format);

        if ($againstSnapshotId !== null) {
            return sprintf('%s&against_snapshot=%d', $base, $againstSnapshotId);
        }

        if ($date !== null) {
            return sprintf('%s&date=%s', $base, $date);
        }

        return $base;
    }

    private function renderComparisonMarkdown(array $comparison): string
    {
        $lines = [
            '# Operations Control Tower Snapshot Comparison',
            '',
            sprintf('- Comparison mode: %s', $comparison['mode']),
            sprintf('- Movement: %s', $comparison['movement']),
            sprintf('- Base snapshot ID: %d', $comparison['base']['snapshot_id']),
            sprintf('- Base snapshot date: %s', $comparison['base']['snapshot_date']),
        ];

        if ($comparison['base']['label'] !== null) {
            $lines[] = sprintf('- Base label: %s', $comparison['base']['label']);
        }

        if ($comparison['compare']['type'] === 'snapshot') {
            $lines[] = sprintf('- Compare snapshot ID: %d', (int) $comparison['compare']['snapshot_id']);
            $lines[] = sprintf('- Compare snapshot date: %s', $comparison['compare']['snapshot_date']);
        } else {
            $lines[] = sprintf('- Compare live date: %s', $comparison['compare']['snapshot_date']);
        }

        $lines[] = sprintf('- Overall status: %s -> %s', $comparison['overall_status_change']['from'], $comparison['overall_status_change']['to']);
        $lines[] = sprintf('- Windows match: %s', $comparison['windows']['match'] ? 'yes' : 'no');
        $lines[] = '';
        $lines[] = '## Delta';
        $lines[] = sprintf('- Sales completed total delta: %s', number_format((float) $comparison['delta']['sales_completed_total'], 2, '.', ''));
        $lines[] = sprintf('- Collections total delta: %s', number_format((float) $comparison['delta']['collections_total'], 2, '.', ''));
        $lines[] = sprintf('- Cash discrepancy total delta: %s', number_format((float) $comparison['delta']['cash_discrepancy_total'], 2, '.', ''));
        $lines[] = sprintf('- Billing pending backlog delta: %d', (int) $comparison['delta']['billing_pending_backlog_count']);
        $lines[] = sprintf('- Billing failed backlog delta: %d', (int) $comparison['delta']['billing_failed_backlog_count']);
        $lines[] = sprintf('- Finance overdue total delta: %s', number_format((float) $comparison['delta']['finance_overdue_total'], 2, '.', ''));
        $lines[] = sprintf('- Finance broken promises delta: %d', (int) $comparison['delta']['finance_broken_promise_count']);
        $lines[] = sprintf('- Operations open alerts delta: %d', (int) $comparison['delta']['operations_open_alert_count']);
        $lines[] = '';
        $lines[] = '## Gate Changes';

        foreach ($comparison['gate_changes'] as $key => $change) {
            $lines[] = sprintf('- %s: %s -> %s', $key, $change['from'], $change['to']);
        }

        return implode("\n", $lines);
    }

    private function filteredIndexQuery(
        int $tenantId,
        ?string $status,
        ?string $fromDate,
        ?string $toDate,
        ?string $label,
    ) {
        $query = DB::table('operations_control_tower_snapshots')
            ->where('operations_control_tower_snapshots.tenant_id', $tenantId);

        if ($status !== null) {
            $query->where('operations_control_tower_snapshots.overall_status', $status);
        }

        if ($fromDate !== null) {
            $query->whereDate('operations_control_tower_snapshots.snapshot_date', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->whereDate('operations_control_tower_snapshots.snapshot_date', '<=', $toDate);
        }

        if ($label !== null) {
            $query->whereRaw(
                "operations_control_tower_snapshots.label like ? escape '\\'",
                ['%'.$this->escapeLikePattern($label).'%'],
            );
        }

        return $query;
    }

    private function normalizeLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        if ($label === '') {
            return null;
        }

        if (mb_strlen($label) > 120) {
            throw new HttpException(422, 'Snapshot label is too long.');
        }

        return $label;
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Snapshot limit is invalid.');
        }
    }

    private function assertStatus(?string $status): void
    {
        if ($status !== null && ! in_array($status, ['ok', 'warning', 'critical'], true)) {
            throw new HttpException(422, 'Snapshot status filter is invalid.');
        }
    }

    private function normalizeSearchLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : $label;
    }

    private function escapeLikePattern(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private function assertComparisonTarget(?int $againstSnapshotId, ?string $date): void
    {
        if ($againstSnapshotId !== null && $againstSnapshotId <= 0) {
            throw new HttpException(422, 'against_snapshot is invalid.');
        }

        if ($againstSnapshotId !== null && $date !== null) {
            throw new HttpException(422, 'Snapshot comparison target is ambiguous.');
        }
    }
}
