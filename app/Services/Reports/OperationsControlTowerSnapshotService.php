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

    public function index(int $tenantId, int $limit = 20): array
    {
        $this->assertTenantId($tenantId);
        $this->assertLimit($limit);

        $items = DB::table('operations_control_tower_snapshots')
            ->leftJoin('users', 'users.id', '=', 'operations_control_tower_snapshots.user_id')
            ->where('operations_control_tower_snapshots.tenant_id', $tenantId)
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
            'summary' => [
                'count' => count($items),
                'critical_count' => count(array_filter($items, fn (array $item) => $item['executive_summary']['overall_status'] === 'critical')),
                'warning_count' => count(array_filter($items, fn (array $item) => $item['executive_summary']['overall_status'] === 'warning')),
                'ok_count' => count(array_filter($items, fn (array $item) => $item['executive_summary']['overall_status'] === 'ok')),
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
}
