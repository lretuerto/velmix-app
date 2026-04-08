<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsEscalationMetricsService
{
    private const MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly OperationsEscalationReportService $reportService,
        private readonly BillingEscalationMetricsService $billingMetricsService,
        private readonly FinanceEscalationMetricsService $financeMetricsService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $historyDays = 30,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertBillingDays($billingDays);
        $this->assertFinanceDaysAhead($financeDaysAhead);
        $this->assertHistoryDays($historyDays);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $baseDate = $this->resolveBaseDate($date);
        $activeReport = $this->reportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $billingDays,
            $financeDaysAhead,
            count(BillingEscalationReportService::KNOWN_CODES) + count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );
        $billing = $this->billingMetricsService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $billingDays,
            $historyDays,
        );
        $finance = $this->financeMetricsService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $financeDaysAhead,
            $historyDays,
            $staleFollowUpDays,
        );

        $billingResolvedCount = (int) ($billing['resolution_sla']['resolved_count'] ?? 0);
        $financeResolvedCount = (int) ($finance['resolution_sla']['resolved_count'] ?? 0);
        $resolvedCount = $billingResolvedCount + $financeResolvedCount;
        $withinCount = $this->weightedWithinCount($billing['resolution_sla'] ?? [], $billingResolvedCount)
            + $this->weightedWithinCount($finance['resolution_sla'] ?? [], $financeResolvedCount);

        return [
            'tenant_id' => $tenantId,
            'active_window' => $activeReport['window'],
            'history_window' => [
                'days' => $historyDays,
                'start_date' => $baseDate->subDays($historyDays - 1)->toDateString(),
                'end_date' => $baseDate->toDateString(),
            ],
            'current_backlog' => [
                'active_count' => $activeReport['summary']['open_count'],
                'open_count' => $activeReport['summary']['workflow']['open_count'],
                'acknowledged_count' => $activeReport['summary']['workflow']['acknowledged_count'],
                'resolved_but_active_count' => $activeReport['summary']['workflow']['resolved_count'],
                'critical_count' => $activeReport['summary']['critical_count'],
                'warning_count' => $activeReport['summary']['warning_count'],
                'info_count' => $activeReport['summary']['info_count'],
                'by_domain' => $activeReport['summary']['by_domain'],
                'stale_acknowledged_count' => (int) ($billing['current_backlog']['stale_acknowledged_count'] ?? 0)
                    + (int) ($finance['current_backlog']['stale_acknowledged_count'] ?? 0),
            ],
            'workflow_events' => [
                'acknowledged_event_count' => (int) ($billing['workflow_events']['acknowledged_event_count'] ?? 0)
                    + (int) ($finance['workflow_events']['acknowledged_event_count'] ?? 0),
                'resolved_event_count' => (int) ($billing['workflow_events']['resolved_event_count'] ?? 0)
                    + (int) ($finance['workflow_events']['resolved_event_count'] ?? 0),
                'latest_acknowledged_at' => $this->latestDate(
                    $billing['workflow_events']['latest_acknowledged_at'] ?? null,
                    $finance['workflow_events']['latest_acknowledged_at'] ?? null,
                ),
                'latest_resolved_at' => $this->latestDate(
                    $billing['workflow_events']['latest_resolved_at'] ?? null,
                    $finance['workflow_events']['latest_resolved_at'] ?? null,
                ),
            ],
            'resolution_sla' => [
                'resolved_count' => $resolvedCount,
                'avg_minutes_from_ack_to_resolve' => $this->weightedAverage([
                    [
                        'count' => $billingResolvedCount,
                        'value' => $billing['resolution_sla']['avg_minutes_from_ack_to_resolve'] ?? null,
                    ],
                    [
                        'count' => $financeResolvedCount,
                        'value' => $finance['resolution_sla']['avg_minutes_from_ack_to_resolve'] ?? null,
                    ],
                ]),
                'max_minutes_from_ack_to_resolve' => $this->maxNullable([
                    $billing['resolution_sla']['max_minutes_from_ack_to_resolve'] ?? null,
                    $finance['resolution_sla']['max_minutes_from_ack_to_resolve'] ?? null,
                ]),
                'within_240_minutes_rate' => $resolvedCount > 0
                    ? round(($withinCount / $resolvedCount) * 100, 2)
                    : null,
            ],
            'recent_resolutions' => $this->mergeRecentResolutions($billing, $finance),
            'current_high_priority_queue' => collect($activeReport['queue'])
                ->take(5)
                ->map(fn (array $item) => [
                    'domain' => $item['domain'],
                    'queue_key' => $item['queue_key'],
                    'code' => $item['code'],
                    'severity' => $item['severity'],
                    'workflow_status' => $item['workflow_status'],
                    'priority' => $item['priority'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function mergeRecentResolutions(array $billing, array $finance): array
    {
        $items = collect(array_merge(
            array_map(fn (array $item) => array_merge(['domain' => 'billing'], $item), $billing['recent_resolutions'] ?? []),
            array_map(fn (array $item) => array_merge(['domain' => 'finance'], $item), $finance['recent_resolutions'] ?? []),
        ));

        return $items
            ->sortByDesc('resolved_at')
            ->take(5)
            ->values()
            ->all();
    }

    private function weightedWithinCount(array $resolutionSla, int $resolvedCount): float
    {
        $rate = $resolutionSla['within_240_minutes_rate'] ?? null;

        if ($resolvedCount <= 0 || $rate === null) {
            return 0;
        }

        return $resolvedCount * ((float) $rate / 100);
    }

    private function weightedAverage(array $items): ?float
    {
        $totalCount = 0;
        $total = 0.0;

        foreach ($items as $item) {
            $count = (int) ($item['count'] ?? 0);
            $value = $item['value'];

            if ($count <= 0 || $value === null) {
                continue;
            }

            $totalCount += $count;
            $total += $count * (float) $value;
        }

        return $totalCount > 0 ? round($total / $totalCount, 2) : null;
    }

    private function maxNullable(array $values): ?float
    {
        $filtered = array_values(array_filter($values, fn ($value) => $value !== null));

        return $filtered !== [] ? round((float) max($filtered), 2) : null;
    }

    private function latestDate(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return CarbonImmutable::parse($left)->gte(CarbonImmutable::parse($right)) ? $left : $right;
    }

    private function resolveBaseDate(?string $date): CarbonImmutable
    {
        return $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }
    }

    private function assertBillingDays(int $billingDays): void
    {
        if ($billingDays < 1 || $billingDays > 14) {
            throw new HttpException(422, 'billing_days is invalid.');
        }
    }

    private function assertFinanceDaysAhead(int $financeDaysAhead): void
    {
        if ($financeDaysAhead < 1 || $financeDaysAhead > 30) {
            throw new HttpException(422, 'finance_days_ahead is invalid.');
        }
    }

    private function assertHistoryDays(int $historyDays): void
    {
        if ($historyDays < 1 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Unified escalation metrics history window is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
    }
}
