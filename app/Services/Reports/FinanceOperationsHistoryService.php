<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceOperationsHistoryService
{
    private const MAX_LIMIT = 20;
    private const MAX_ACTIVITY_LIMIT = 50;
    private const MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly FinanceOperationsReportService $reportService,
        private readonly FinanceOperationsStateService $stateService,
    ) {
    }

    public function index(
        int $tenantId,
        ?string $date = null,
        int $daysAhead = 7,
        int $historyDays = 30,
        int $limit = 10,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertHistoryDays($historyDays);
        $this->assertLimit($limit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $baseDate = $this->resolveBaseDate($date);
        $activeItems = collect($this->reportService->workflowItems($tenantId, $baseDate->toDateString(), $daysAhead));
        $activeByEntity = $activeItems->keyBy('entity_key')->all();
        $statesByEntity = $this->stateService->listByEntity($tenantId);
        $activitiesByEntity = $this->activitiesByEntity($tenantId, $historyDays, $baseDate);

        $trackedEntityKeys = collect(array_keys($activeByEntity))
            ->concat(array_keys($statesByEntity))
            ->concat(array_keys($activitiesByEntity))
            ->unique()
            ->values();

        $items = $trackedEntityKeys
            ->map(function (string $entityKey) use ($tenantId, $baseDate, $activeByEntity, $statesByEntity, $activitiesByEntity) {
                [$kind, $entityId] = $this->parseEntityKey($entityKey);

                return $this->buildIndexItem(
                    $kind,
                    $entityId,
                    $this->entitySnapshot($tenantId, $kind, $entityId, $baseDate),
                    $activeByEntity[$entityKey] ?? null,
                    $statesByEntity[$entityKey] ?? null,
                    $activitiesByEntity[$entityKey] ?? [],
                );
            })
            ->sort(function (array $left, array $right): int {
                if ($left['is_currently_prioritized'] !== $right['is_currently_prioritized']) {
                    return $left['is_currently_prioritized'] ? -1 : 1;
                }

                if ($left['last_activity_at'] !== $right['last_activity_at']) {
                    return strcmp((string) $right['last_activity_at'], (string) $left['last_activity_at']);
                }

                return strcmp($left['entity_key'], $right['entity_key']);
            })
            ->values()
            ->all();

        return [
            'tenant_id' => $tenantId,
            'active_window' => [
                'date' => $baseDate->toDateString(),
                'days_ahead' => $daysAhead,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'history_window' => $this->historyWindowData($historyDays, $baseDate),
            'summary' => [
                'tracked_count' => count($items),
                'active_count' => count(array_filter($items, fn (array $item) => $item['is_currently_prioritized'])),
                'with_history_count' => count(array_filter($items, fn (array $item) => $item['timeline_count'] > 0)),
                'acknowledged_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                'resolved_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'resolved')),
            ],
            'items' => array_slice($items, 0, $limit),
        ];
    }

    public function detail(
        int $tenantId,
        string $kind,
        int $entityId,
        ?string $date = null,
        int $daysAhead = 7,
        int $historyDays = 30,
        int $activityLimit = 20,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertHistoryDays($historyDays);
        $this->assertActivityLimit($activityLimit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        if (! in_array($kind, ['receivable', 'payable'], true)) {
            throw new HttpException(404, 'Finance operation kind not found.');
        }

        $baseDate = $this->resolveBaseDate($date);
        $entityKey = $this->stateService->entityKey($kind, $entityId);
        $activeItem = collect($this->reportService->workflowItems($tenantId, $baseDate->toDateString(), $daysAhead))
            ->firstWhere('entity_key', $entityKey);
        $state = $this->stateService->listByEntity($tenantId)[$entityKey] ?? null;
        $timeline = $this->activitiesByEntity($tenantId, $historyDays, $baseDate)[$entityKey] ?? [];
        $snapshot = $this->entitySnapshot($tenantId, $kind, $entityId, $baseDate);

        if ($activeItem === null && $state === null && $timeline === [] && $snapshot === null) {
            throw new HttpException(404, 'Finance operation history not found.');
        }

        $latestActivity = $timeline[0] ?? null;

        return [
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'entity_id' => $entityId,
            'active_window' => [
                'date' => $baseDate->toDateString(),
                'days_ahead' => $daysAhead,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'history_window' => $this->historyWindowData($historyDays, $baseDate),
            'workflow_status' => $activeItem['workflow_status'] ?? $state['status'] ?? 'open',
            'is_currently_prioritized' => $activeItem !== null,
            'is_outstanding' => $snapshot['is_outstanding'] ?? false,
            'entity' => $snapshot,
            'active_item' => $activeItem,
            'state' => $state,
            'latest_note' => $latestActivity['metadata']['note'] ?? $state['resolution_note'] ?? $state['acknowledgement_note'] ?? null,
            'latest_activity' => $latestActivity,
            'timeline_summary' => [
                'total_count' => count($timeline),
                'acknowledged_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'finance.operation.acknowledged')),
                'resolved_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'finance.operation.resolved')),
            ],
            'timeline' => array_slice($timeline, 0, $activityLimit),
        ];
    }

    private function buildIndexItem(
        string $kind,
        int $entityId,
        ?array $snapshot,
        ?array $activeItem,
        ?array $state,
        array $timeline,
    ): array {
        $latestActivity = $timeline[0] ?? null;

        return [
            'entity_key' => $this->stateService->entityKey($kind, $entityId),
            'kind' => $kind,
            'entity_id' => $entityId,
            'reference' => $activeItem['reference'] ?? $snapshot['reference'] ?? null,
            'entity_name' => $activeItem['entity_name'] ?? $snapshot['entity_name'] ?? null,
            'outstanding_amount' => $activeItem['outstanding_amount'] ?? $snapshot['outstanding_amount'] ?? null,
            'workflow_status' => $activeItem['workflow_status'] ?? $state['status'] ?? 'open',
            'is_currently_prioritized' => $activeItem !== null,
            'is_outstanding' => $snapshot['is_outstanding'] ?? false,
            'last_activity_at' => $latestActivity['occurred_at'] ?? $state['updated_at'] ?? null,
            'last_event_type' => $latestActivity['event_type'] ?? null,
            'last_actor' => $latestActivity['user'] ?? null,
            'last_note' => $latestActivity['metadata']['note'] ?? $state['resolution_note'] ?? $state['acknowledgement_note'] ?? null,
            'timeline_count' => count($timeline),
            'state' => $state,
            'active_item' => $activeItem,
            'entity' => $snapshot,
        ];
    }

    private function activitiesByEntity(int $tenantId, int $historyDays, CarbonImmutable $baseDate): array
    {
        return $this->fetchActivities($tenantId, $historyDays, $baseDate)
            ->groupBy(fn (array $activity) => (string) ($activity['metadata']['entity_key'] ?? ''))
            ->filter(fn (Collection $group, string $entityKey) => $entityKey !== '')
            ->map(fn (Collection $group) => $group->values()->all())
            ->all();
    }

    private function fetchActivities(int $tenantId, int $historyDays, CarbonImmutable $baseDate): Collection
    {
        $window = $this->historyWindow($historyDays, $baseDate);

        return DB::table('tenant_activity_logs')
            ->leftJoin('users', 'users.id', '=', 'tenant_activity_logs.user_id')
            ->where('tenant_activity_logs.tenant_id', $tenantId)
            ->where('tenant_activity_logs.domain', 'finance')
            ->where('tenant_activity_logs.aggregate_type', 'finance_operation_state')
            ->whereIn('tenant_activity_logs.event_type', [
                'finance.operation.acknowledged',
                'finance.operation.resolved',
            ])
            ->where('tenant_activity_logs.occurred_at', '>=', $window['start_at'])
            ->where('tenant_activity_logs.occurred_at', '<=', $window['end_at'])
            ->orderByDesc('tenant_activity_logs.occurred_at')
            ->orderByDesc('tenant_activity_logs.id')
            ->get([
                'tenant_activity_logs.id',
                'tenant_activity_logs.user_id',
                'tenant_activity_logs.event_type',
                'tenant_activity_logs.summary',
                'tenant_activity_logs.metadata',
                'tenant_activity_logs.occurred_at',
                'users.name as user_name',
            ])
            ->map(function (object $activity): array {
                $metadata = $activity->metadata !== null
                    ? json_decode((string) $activity->metadata, true, 512, JSON_THROW_ON_ERROR)
                    : [];

                if (isset($metadata['entity_type'], $metadata['entity_id'])) {
                    $metadata['entity_key'] = $this->stateService->entityKey(
                        (string) $metadata['entity_type'],
                        (int) $metadata['entity_id'],
                    );
                }

                return [
                    'id' => (int) $activity->id,
                    'event_type' => (string) $activity->event_type,
                    'summary' => (string) $activity->summary,
                    'metadata' => $metadata,
                    'occurred_at' => (string) $activity->occurred_at,
                    'user' => $activity->user_id !== null ? [
                        'id' => (int) $activity->user_id,
                        'name' => $activity->user_name,
                    ] : null,
                ];
            });
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
                    'sale_receivables.total_amount',
                    'sale_receivables.paid_amount',
                    'sale_receivables.outstanding_amount',
                    'sale_receivables.status',
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
                'total_amount' => round((float) $row->total_amount, 2),
                'paid_amount' => round((float) $row->paid_amount, 2),
                'outstanding_amount' => round((float) $row->outstanding_amount, 2),
                'status' => (string) $row->status,
                'due_at' => $row->due_at !== null ? CarbonImmutable::parse((string) $row->due_at)->toDateString() : null,
                'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
                'days_until_due' => $this->daysUntilDue($row->due_at, $baseDate),
                'is_outstanding' => (float) $row->outstanding_amount > 0,
            ];
        }

        $row = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.id', $entityId)
            ->first([
                'purchase_payables.id',
                'purchase_payables.total_amount',
                'purchase_payables.paid_amount',
                'purchase_payables.outstanding_amount',
                'purchase_payables.status',
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
            'total_amount' => round((float) $row->total_amount, 2),
            'paid_amount' => round((float) $row->paid_amount, 2),
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
            'status' => (string) $row->status,
            'due_at' => $row->due_at !== null ? CarbonImmutable::parse((string) $row->due_at)->toDateString() : null,
            'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
            'days_until_due' => $this->daysUntilDue($row->due_at, $baseDate),
            'is_outstanding' => (float) $row->outstanding_amount > 0,
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

    private function historyWindowData(int $historyDays, CarbonImmutable $baseDate): array
    {
        $window = $this->historyWindow($historyDays, $baseDate);

        return [
            'days' => $window['days'],
            'start_date' => $window['start_date'],
            'end_date' => $window['end_date'],
        ];
    }

    private function resolveBaseDate(?string $date): CarbonImmutable
    {
        return $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    private function parseEntityKey(string $entityKey): array
    {
        [$kind, $entityId] = explode(':', $entityKey, 2);

        return [$kind, (int) $entityId];
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

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }
    }

    private function assertHistoryDays(int $historyDays): void
    {
        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Finance history window is invalid.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Finance history limit is invalid.');
        }
    }

    private function assertActivityLimit(int $activityLimit): void
    {
        if ($activityLimit <= 0 || $activityLimit > self::MAX_ACTIVITY_LIMIT) {
            throw new HttpException(422, 'Finance history activity limit is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
    }
}
