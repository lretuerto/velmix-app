<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceOperationsReportService
{
    public function __construct(
        private readonly PromiseInsightService $promiseInsights,
        private readonly DueReminderReportService $dueReminders,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $daysAhead = 7,
        int $limit = 5,
        int $staleFollowUpDays = 3,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($daysAhead < 1 || $daysAhead > 30) {
            throw new HttpException(422, 'days_ahead is invalid.');
        }

        if ($limit < 1 || $limit > 20) {
            throw new HttpException(422, 'limit is invalid.');
        }

        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }

        $baseDate = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $receivables = $this->receivables($tenantId, $baseDate);
        $payables = $this->payables($tenantId, $baseDate);
        $dueReminders = $this->dueReminders->summary($tenantId, $daysAhead, $limit, $baseDate->toDateString());

        return [
            'tenant_id' => $tenantId,
            'date' => $baseDate->toDateString(),
            'days_ahead' => $daysAhead,
            'stale_follow_up_days' => $staleFollowUpDays,
            'receivables' => $this->buildDomainPayload(
                $tenantId,
                'receivable',
                $receivables,
                $baseDate,
                $staleFollowUpDays,
            ),
            'payables' => $this->buildDomainPayload(
                $tenantId,
                'payable',
                $payables,
                $baseDate,
                $staleFollowUpDays,
            ),
            'combined' => $this->combinedSummary($tenantId, $receivables, $payables, $baseDate, $staleFollowUpDays),
            'priority_queue' => $this->priorityQueue($dueReminders, $limit),
        ];
    }

    private function receivables(int $tenantId, CarbonImmutable $baseDate): Collection
    {
        $rows = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.outstanding_amount', '>', 0)
            ->get([
                'sale_receivables.id',
                'sale_receivables.sale_id',
                'sale_receivables.customer_id',
                'sale_receivables.total_amount',
                'sale_receivables.paid_amount',
                'sale_receivables.outstanding_amount',
                'sale_receivables.status',
                'sale_receivables.due_at',
                'customers.name as customer_name',
                'sales.reference as sale_reference',
            ]);

        $followUps = $this->latestFollowUps(
            'sale_receivable_follow_ups',
            'sale_receivable_id',
            $tenantId,
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $promises = collect($this->promiseInsights->latestReceivablePromises(
            $tenantId,
            $baseDate,
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ));

        return $rows->map(function (object $row) use ($followUps, $promises, $baseDate) {
            $promise = $promises->get((int) $row->id);

            return [
                'kind' => 'receivable',
                'id' => (int) $row->id,
                'entity_name' => (string) $row->customer_name,
                'reference' => (string) $row->sale_reference,
                'outstanding_amount' => round((float) $row->outstanding_amount, 2),
                'due_at' => $row->due_at !== null ? CarbonImmutable::parse((string) $row->due_at)->toDateString() : null,
                'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
                'latest_follow_up' => $followUps[(int) $row->id] ?? null,
                'latest_promise' => $promise,
                'promise_status' => $promise['status'] ?? null,
            ];
        });
    }

    private function payables(int $tenantId, CarbonImmutable $baseDate): Collection
    {
        $rows = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.outstanding_amount', '>', 0)
            ->get([
                'purchase_payables.id',
                'purchase_payables.purchase_receipt_id',
                'purchase_payables.supplier_id',
                'purchase_payables.total_amount',
                'purchase_payables.paid_amount',
                'purchase_payables.outstanding_amount',
                'purchase_payables.status',
                'purchase_payables.due_at',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ]);

        $followUps = $this->latestFollowUps(
            'purchase_payable_follow_ups',
            'purchase_payable_id',
            $tenantId,
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $promises = collect($this->promiseInsights->latestPayablePromises(
            $tenantId,
            $baseDate,
            $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ));

        return $rows->map(function (object $row) use ($followUps, $promises, $baseDate) {
            $promise = $promises->get((int) $row->id);

            return [
                'kind' => 'payable',
                'id' => (int) $row->id,
                'entity_name' => (string) $row->supplier_name,
                'reference' => (string) $row->receipt_reference,
                'outstanding_amount' => round((float) $row->outstanding_amount, 2),
                'due_at' => $row->due_at !== null ? CarbonImmutable::parse((string) $row->due_at)->toDateString() : null,
                'days_overdue' => $this->daysOverdue($row->due_at, $baseDate),
                'latest_follow_up' => $followUps[(int) $row->id] ?? null,
                'latest_promise' => $promise,
                'promise_status' => $promise['status'] ?? null,
            ];
        });
    }

    private function buildDomainPayload(
        int $tenantId,
        string $kind,
        Collection $items,
        CarbonImmutable $baseDate,
        int $staleFollowUpDays,
    ): array {
        $promises = $kind === 'receivable'
            ? collect($this->promiseInsights->latestReceivablePromises($tenantId, $baseDate))
            : collect($this->promiseInsights->latestPayablePromises($tenantId, $baseDate));

        $overdue = $items->filter(fn (array $item) => $item['days_overdue'] > 0);
        $current = $items->filter(fn (array $item) => $item['days_overdue'] === 0);
        $staleThreshold = $baseDate->subDays($staleFollowUpDays);
        $missingFollowUp = $items->filter(fn (array $item) => $item['latest_follow_up'] === null);
        $staleFollowUp = $items->filter(function (array $item) use ($staleThreshold) {
            $createdAt = $item['latest_follow_up']['created_at'] ?? null;

            return $createdAt !== null && CarbonImmutable::parse($createdAt)->lt($staleThreshold);
        });
        $recentFollowUp = $items->filter(function (array $item) use ($staleThreshold) {
            $createdAt = $item['latest_follow_up']['created_at'] ?? null;

            return $createdAt !== null && ! CarbonImmutable::parse($createdAt)->lt($staleThreshold);
        });

        return [
            'exposure' => [
                'count' => $items->count(),
                'outstanding_total' => round((float) $items->sum('outstanding_amount'), 2),
                'overdue_count' => $overdue->count(),
                'overdue_total' => round((float) $overdue->sum('outstanding_amount'), 2),
                'current_count' => $current->count(),
                'current_total' => round((float) $current->sum('outstanding_amount'), 2),
            ],
            'promise_compliance' => [
                'broken_count' => $promises->where('status', 'broken')->count(),
                'broken_total' => round((float) $promises->where('status', 'broken')->sum('outstanding_amount'), 2),
                'pending_count' => $promises->where('status', 'pending')->count(),
                'pending_total' => round((float) $promises->where('status', 'pending')->sum('outstanding_amount'), 2),
                'fulfilled_count' => $promises->where('status', 'fulfilled')->count(),
                'fulfilled_total' => round((float) $promises->where('status', 'fulfilled')->sum('resolved_amount'), 2),
            ],
            'follow_up_health' => [
                'missing_count' => $missingFollowUp->count(),
                'missing_total' => round((float) $missingFollowUp->sum('outstanding_amount'), 2),
                'stale_count' => $staleFollowUp->count(),
                'stale_total' => round((float) $staleFollowUp->sum('outstanding_amount'), 2),
                'recent_count' => $recentFollowUp->count(),
                'recent_total' => round((float) $recentFollowUp->sum('outstanding_amount'), 2),
            ],
        ];
    }

    private function combinedSummary(
        int $tenantId,
        Collection $receivables,
        Collection $payables,
        CarbonImmutable $baseDate,
        int $staleFollowUpDays,
    ): array {
        $receivableDomain = $this->buildDomainPayload($tenantId, 'receivable', $receivables, $baseDate, $staleFollowUpDays);
        $payableDomain = $this->buildDomainPayload($tenantId, 'payable', $payables, $baseDate, $staleFollowUpDays);

        return [
            'outstanding_total' => round($receivableDomain['exposure']['outstanding_total'] + $payableDomain['exposure']['outstanding_total'], 2),
            'overdue_total' => round($receivableDomain['exposure']['overdue_total'] + $payableDomain['exposure']['overdue_total'], 2),
            'broken_promise_count' => $receivableDomain['promise_compliance']['broken_count'] + $payableDomain['promise_compliance']['broken_count'],
            'stale_follow_up_count' => $receivableDomain['follow_up_health']['stale_count'] + $payableDomain['follow_up_health']['stale_count'],
            'missing_follow_up_count' => $receivableDomain['follow_up_health']['missing_count'] + $payableDomain['follow_up_health']['missing_count'],
        ];
    }

    private function priorityQueue(array $dueReminders, int $limit): array
    {
        $items = collect();

        foreach (['receivables', 'payables'] as $bucket) {
            foreach (['overdue', 'due_today', 'upcoming'] as $section) {
                foreach ($dueReminders[$bucket][$section] as $item) {
                    $items->push([
                        'kind' => $bucket === 'receivables' ? 'receivable' : 'payable',
                        'reference' => $item['sale_reference'] ?? $item['receipt_reference'] ?? null,
                        'entity_name' => $item['customer_name'] ?? $item['supplier_name'] ?? null,
                        'outstanding_amount' => (float) $item['outstanding_amount'],
                        'due_at' => $item['due_at'],
                        'days_overdue' => (int) ($item['days_overdue'] ?? 0),
                        'promise_status' => $item['promise_status'] ?? null,
                        'escalation_level' => $item['escalation_level'] ?? 'normal',
                        'latest_follow_up' => $item['latest_follow_up'] ?? null,
                    ]);
                }
            }
        }

        $priorityRank = [
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'attention' => 2,
            'watch' => 1,
            'normal' => 0,
        ];

        return $items
            ->sort(function (array $left, array $right) use ($priorityRank): int {
                $leftRank = $priorityRank[$left['escalation_level']] ?? 0;
                $rightRank = $priorityRank[$right['escalation_level']] ?? 0;

                if ($leftRank !== $rightRank) {
                    return $rightRank <=> $leftRank;
                }

                if ($left['days_overdue'] !== $right['days_overdue']) {
                    return $right['days_overdue'] <=> $left['days_overdue'];
                }

                if ($left['outstanding_amount'] !== $right['outstanding_amount']) {
                    return $right['outstanding_amount'] <=> $left['outstanding_amount'];
                }

                return strcmp((string) $left['reference'], (string) $right['reference']);
            })
            ->take($limit)
            ->values()
            ->all();
    }

    private function latestFollowUps(string $table, string $entityColumn, int $tenantId, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        $rows = DB::table($table)
            ->join('users', 'users.id', '=', $table.'.user_id')
            ->where($table.'.tenant_id', $tenantId)
            ->whereIn($table.'.'.$entityColumn, $entityIds)
            ->orderByDesc($table.'.id')
            ->get([
                $table.'.id',
                $table.'.'.$entityColumn,
                $table.'.type',
                $table.'.note',
                $table.'.promised_amount',
                $table.'.outstanding_snapshot',
                $table.'.promised_at',
                $table.'.created_at',
                'users.id as user_id',
                'users.name as user_name',
            ]);

        $latest = [];

        foreach ($rows as $row) {
            $entityId = (int) $row->{$entityColumn};

            if (! array_key_exists($entityId, $latest)) {
                $latest[$entityId] = [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'note' => (string) $row->note,
                    'promised_amount' => $row->promised_amount !== null ? (float) $row->promised_amount : null,
                    'outstanding_snapshot' => $row->outstanding_snapshot !== null ? (float) $row->outstanding_snapshot : null,
                    'promised_at' => $row->promised_at,
                    'created_at' => (string) $row->created_at,
                    'user' => [
                        'id' => (int) $row->user_id,
                        'name' => (string) $row->user_name,
                    ],
                ];
            }
        }

        return $latest;
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
}
