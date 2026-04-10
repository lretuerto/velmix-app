<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceOperationsMetricsService
{
    private const MAX_HISTORY_DAYS = 90;
    private const STALE_ACKNOWLEDGED_HOURS = 24;

    public function __construct(
        private readonly FinanceOperationsReportService $reportService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $daysAhead = 7,
        int $historyDays = 30,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Finance metrics history window is invalid.');
        }

        $baseDate = $this->resolveBaseDate($date);
        $referenceTime = $baseDate->endOfDay();
        $activeItems = collect($this->reportService->workflowItems($tenantId, $baseDate->toDateString(), $daysAhead));
        $historyWindow = $this->historyWindow($historyDays, $baseDate);
        $activities = $this->fetchActivities($tenantId, $historyWindow['start_at'], $historyWindow['end_at']);
        $resolutionPairs = $this->buildResolutionPairs($activities);
        $recentResolutions = $resolutionPairs
            ->sortByDesc('resolved_at')
            ->take(5)
            ->values()
            ->map(function (array $pair) use ($tenantId, $baseDate, $activeItems) {
                $activeItem = $activeItems->firstWhere('entity_key', $pair['entity_key']);
                $snapshot = $this->entitySnapshot($tenantId, $pair['kind'], $pair['entity_id'], $baseDate);

                return [
                    'entity_key' => $pair['entity_key'],
                    'kind' => $pair['kind'],
                    'entity_id' => $pair['entity_id'],
                    'reference' => $activeItem['reference'] ?? $snapshot['reference'] ?? null,
                    'entity_name' => $activeItem['entity_name'] ?? $snapshot['entity_name'] ?? null,
                    'acknowledged_at' => $pair['acknowledged_at'],
                    'resolved_at' => $pair['resolved_at'],
                    'minutes_from_ack_to_resolve' => $pair['minutes_from_ack_to_resolve'],
                    'resolved_by' => $pair['resolved_by'],
                    'resolution_note' => $pair['resolution_note'],
                    'is_currently_prioritized' => $activeItem !== null,
                ];
            })
            ->all();

        $staleAcknowledged = $activeItems
            ->filter(function (array $item) use ($referenceTime) {
                $acknowledgedAt = $item['state']['acknowledged_at'] ?? null;

                if ($item['workflow_status'] !== 'acknowledged' || $acknowledgedAt === null) {
                    return false;
                }

                return CarbonImmutable::parse($acknowledgedAt)
                    ->lt($referenceTime->subHours(self::STALE_ACKNOWLEDGED_HOURS));
            })
            ->values();

        $acknowledgedAges = $activeItems
            ->filter(fn (array $item) => $item['workflow_status'] === 'acknowledged' && ($item['state']['acknowledged_at'] ?? null) !== null)
            ->map(fn (array $item) => $this->diffMinutes(
                (string) $item['state']['acknowledged_at'],
                $referenceTime->toDateTimeString(),
            ))
            ->values();

        return [
            'tenant_id' => $tenantId,
            'active_window' => [
                'date' => $baseDate->toDateString(),
                'days_ahead' => $daysAhead,
            ],
            'history_window' => [
                'days' => $historyWindow['days'],
                'start_date' => $historyWindow['start_date'],
                'end_date' => $historyWindow['end_date'],
            ],
            'current_backlog' => [
                'active_count' => $activeItems->count(),
                'open_count' => $activeItems->where('workflow_status', 'open')->count(),
                'acknowledged_count' => $activeItems->where('workflow_status', 'acknowledged')->count(),
                'resolved_but_active_count' => $activeItems->where('workflow_status', 'resolved')->count(),
                'critical_count' => $activeItems->where('escalation_level', 'critical')->count(),
                'high_count' => $activeItems->where('escalation_level', 'high')->count(),
                'medium_count' => $activeItems->where('escalation_level', 'medium')->count(),
                'attention_count' => $activeItems->where('escalation_level', 'attention')->count(),
                'watch_count' => $activeItems->where('escalation_level', 'watch')->count(),
                'stale_acknowledged_count' => $staleAcknowledged->count(),
                'oldest_acknowledged_age_minutes' => $acknowledgedAges->isNotEmpty()
                    ? round((float) $acknowledgedAges->max(), 2)
                    : null,
            ],
            'backlog_by_kind' => [
                'receivable' => [
                    'count' => $activeItems->where('kind', 'receivable')->count(),
                    'outstanding_total' => round((float) $activeItems->where('kind', 'receivable')->sum('outstanding_amount'), 2),
                ],
                'payable' => [
                    'count' => $activeItems->where('kind', 'payable')->count(),
                    'outstanding_total' => round((float) $activeItems->where('kind', 'payable')->sum('outstanding_amount'), 2),
                ],
            ],
            'queue_aging' => [
                'overdue_count' => $activeItems->filter(fn (array $item) => $item['days_overdue'] > 0)->count(),
                'due_today_count' => $activeItems->filter(fn (array $item) => $item['days_overdue'] === 0 && $item['days_until_due'] === 0)->count(),
                'upcoming_count' => $activeItems->filter(fn (array $item) => $item['days_until_due'] > 0)->count(),
                'oldest_overdue_days' => $activeItems->filter(fn (array $item) => $item['days_overdue'] > 0)->max('days_overdue'),
                'avg_days_overdue' => $activeItems->filter(fn (array $item) => $item['days_overdue'] > 0)->isNotEmpty()
                    ? round((float) $activeItems->filter(fn (array $item) => $item['days_overdue'] > 0)->avg('days_overdue'), 2)
                    : null,
            ],
            'workflow_events' => [
                'acknowledged_event_count' => $activities->where('event_type', 'finance.operation.acknowledged')->count(),
                'resolved_event_count' => $activities->where('event_type', 'finance.operation.resolved')->count(),
                'latest_acknowledged_at' => $activities->firstWhere('event_type', 'finance.operation.acknowledged')['occurred_at'] ?? null,
                'latest_resolved_at' => $activities->firstWhere('event_type', 'finance.operation.resolved')['occurred_at'] ?? null,
            ],
            'resolution_sla' => [
                'resolved_count' => $resolutionPairs->count(),
                'avg_minutes_from_ack_to_resolve' => $resolutionPairs->isNotEmpty()
                    ? round((float) $resolutionPairs->avg('minutes_from_ack_to_resolve'), 2)
                    : null,
                'max_minutes_from_ack_to_resolve' => $resolutionPairs->isNotEmpty()
                    ? round((float) $resolutionPairs->max('minutes_from_ack_to_resolve'), 2)
                    : null,
                'within_240_minutes_rate' => $resolutionPairs->isNotEmpty()
                    ? round(($resolutionPairs->filter(fn (array $pair) => $pair['minutes_from_ack_to_resolve'] <= 240)->count() / $resolutionPairs->count()) * 100, 2)
                    : null,
            ],
            'recent_resolutions' => $recentResolutions,
            'current_high_priority_items' => $activeItems
                ->take(5)
                ->values()
                ->map(fn (array $item) => [
                    'entity_key' => $item['entity_key'],
                    'kind' => $item['kind'],
                    'entity_id' => $item['entity_id'],
                    'reference' => $item['reference'],
                    'workflow_status' => $item['workflow_status'],
                    'escalation_level' => $item['escalation_level'],
                    'outstanding_amount' => $item['outstanding_amount'],
                ])
                ->all(),
        ];
    }

    private function fetchActivities(int $tenantId, CarbonImmutable $startAt, CarbonImmutable $endAt): Collection
    {
        return DB::table('tenant_activity_logs')
            ->leftJoin('users', 'users.id', '=', 'tenant_activity_logs.user_id')
            ->where('tenant_activity_logs.tenant_id', $tenantId)
            ->where('tenant_activity_logs.domain', 'finance')
            ->where('tenant_activity_logs.aggregate_type', 'finance_operation_state')
            ->whereIn('tenant_activity_logs.event_type', [
                'finance.operation.acknowledged',
                'finance.operation.resolved',
            ])
            ->where('tenant_activity_logs.occurred_at', '>=', $startAt)
            ->where('tenant_activity_logs.occurred_at', '<=', $endAt)
            ->orderByDesc('tenant_activity_logs.occurred_at')
            ->orderByDesc('tenant_activity_logs.id')
            ->get([
                'tenant_activity_logs.id',
                'tenant_activity_logs.event_type',
                'tenant_activity_logs.metadata',
                'tenant_activity_logs.occurred_at',
                'users.id as user_id',
                'users.name as user_name',
            ])
            ->map(function (object $activity): array {
                return [
                    'id' => (int) $activity->id,
                    'event_type' => (string) $activity->event_type,
                    'metadata' => $activity->metadata !== null
                        ? json_decode((string) $activity->metadata, true, 512, JSON_THROW_ON_ERROR)
                        : [],
                    'occurred_at' => (string) $activity->occurred_at,
                    'user' => $activity->user_id !== null ? [
                        'id' => (int) $activity->user_id,
                        'name' => $activity->user_name,
                    ] : null,
                ];
            });
    }

    private function buildResolutionPairs(Collection $activities): Collection
    {
        return $activities
            ->sortBy('occurred_at')
            ->groupBy(function (array $activity) {
                $kind = (string) ($activity['metadata']['entity_type'] ?? '');
                $entityId = (int) ($activity['metadata']['entity_id'] ?? 0);

                return $kind !== '' && $entityId > 0 ? $kind.':'.$entityId : '';
            })
            ->filter(fn (Collection $group, string $entityKey) => $entityKey !== '')
            ->flatMap(function (Collection $group, string $entityKey) {
                [$kind, $entityId] = explode(':', $entityKey, 2);
                $pairs = [];
                $lastAcknowledged = null;

                foreach ($group as $activity) {
                    if ($activity['event_type'] === 'finance.operation.acknowledged') {
                        $lastAcknowledged = $activity;
                        continue;
                    }

                    if ($activity['event_type'] !== 'finance.operation.resolved' || $lastAcknowledged === null) {
                        continue;
                    }

                    $pairs[] = [
                        'entity_key' => $entityKey,
                        'kind' => $kind,
                        'entity_id' => (int) $entityId,
                        'acknowledged_at' => $lastAcknowledged['occurred_at'],
                        'resolved_at' => $activity['occurred_at'],
                        'minutes_from_ack_to_resolve' => $this->diffMinutes(
                            $lastAcknowledged['occurred_at'],
                            $activity['occurred_at'],
                        ),
                        'resolved_by' => $activity['user'],
                        'resolution_note' => $activity['metadata']['note'] ?? null,
                    ];

                    $lastAcknowledged = null;
                }

                return $pairs;
            })
            ->values();
    }

    private function entitySnapshot(int $tenantId, string $kind, int $entityId, CarbonImmutable $baseDate): ?array
    {
        if ($kind === 'receivable') {
            $row = DB::table('sale_receivables')
                ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
                ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
                ->where('sale_receivables.tenant_id', $tenantId)
                ->where('sale_receivables.id', $entityId)
                ->first([
                    'sale_receivables.id',
                    'sale_receivables.outstanding_amount',
                    'sale_receivables.due_at',
                    'customers.name as customer_name',
                    'sales.reference as sale_reference',
                ]);

            if ($row === null) {
                return null;
            }

            return [
                'kind' => 'receivable',
                'entity_id' => (int) $row->id,
                'entity_name' => (string) $row->customer_name,
                'reference' => (string) $row->sale_reference,
                'outstanding_amount' => round((float) $row->outstanding_amount, 2),
                'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
                'days_until_due' => $this->daysUntilDue($row->due_at, $baseDate),
            ];
        }

        $row = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.id', $entityId)
            ->first([
                'purchase_payables.id',
                'purchase_payables.outstanding_amount',
                'purchase_payables.due_at',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ]);

        if ($row === null) {
            return null;
        }

        return [
            'kind' => 'payable',
            'entity_id' => (int) $row->id,
            'entity_name' => (string) $row->supplier_name,
            'reference' => (string) $row->receipt_reference,
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
            'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
            'days_until_due' => $this->daysUntilDue($row->due_at, $baseDate),
        ];
    }

    private function historyWindow(int $historyDays, CarbonImmutable $baseDate): array
    {
        $endAt = $baseDate->endOfDay();
        $startAt = $endAt->subDays($historyDays - 1)->startOfDay();

        return [
            'days' => $historyDays,
            'start_date' => $startAt->toDateString(),
            'end_date' => $endAt->toDateString(),
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    private function resolveBaseDate(?string $date): CarbonImmutable
    {
        return $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    private function diffMinutes(string $from, string $to): float
    {
        return round(CarbonImmutable::parse($from)->diffInSeconds(CarbonImmutable::parse($to)) / 60, 2);
    }

    private function daysOverdue(?string $dueAt, CarbonImmutable $baseDate): int
    {
        if ($dueAt === null) {
            return 0;
        }

        $dueDate = CarbonImmutable::parse($dueAt)->startOfDay();

        if ($dueDate->gte($baseDate)) {
            return 0;
        }

        return abs($baseDate->diffInDays($dueDate, false));
    }

    private function daysUntilDue(?string $dueAt, CarbonImmutable $baseDate): int
    {
        if ($dueAt === null) {
            return 0;
        }

        return $baseDate->diffInDays(CarbonImmutable::parse($dueAt)->startOfDay(), false);
    }
}
