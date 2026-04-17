<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsEscalationHistoryService
{
    private const MAX_LIMIT = 20;
    private const MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly BillingEscalationHistoryService $billingHistoryService,
        private readonly FinanceEscalationHistoryService $financeHistoryService,
    ) {
    }

    public function index(
        int $tenantId,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $historyDays = 30,
        int $limit = 10,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertBillingDays($billingDays);
        $this->assertFinanceDaysAhead($financeDaysAhead);
        $this->assertHistoryDays($historyDays);
        $this->assertLimit($limit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $baseDate = $this->resolveBaseDate($date);
        $billing = $this->billingHistoryService->index(
            $tenantId,
            $baseDate->toDateString(),
            $billingDays,
            $historyDays,
            count(BillingEscalationReportService::KNOWN_CODES),
        );
        $finance = $this->financeHistoryService->index(
            $tenantId,
            $baseDate->toDateString(),
            $financeDaysAhead,
            $historyDays,
            count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );

        $items = array_merge(
            array_map(fn (array $item) => $this->normalizeItem('billing', $item), $billing['items']),
            array_map(fn (array $item) => $this->normalizeItem('finance', $item), $finance['items']),
        );

        usort($items, function (array $left, array $right): int {
            if ($left['is_currently_triggered'] !== $right['is_currently_triggered']) {
                return $left['is_currently_triggered'] ? -1 : 1;
            }

            $leftPriority = $left['priority'] ?? -1;
            $rightPriority = $right['priority'] ?? -1;

            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }

            if ($left['last_activity_at'] !== $right['last_activity_at']) {
                return strcmp((string) $right['last_activity_at'], (string) $left['last_activity_at']);
            }

            if ($left['domain'] !== $right['domain']) {
                return strcmp($left['domain'], $right['domain']);
            }

            return strcmp($left['code'], $right['code']);
        });

        return [
            'tenant_id' => $tenantId,
            'active_window' => [
                'date' => $baseDate->toDateString(),
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'history_window' => [
                'days' => $historyDays,
                'start_date' => $baseDate->subDays($historyDays - 1)->toDateString(),
                'end_date' => $baseDate->toDateString(),
            ],
            'summary' => [
                'tracked_count' => count($items),
                'active_count' => count(array_filter($items, fn (array $item) => $item['is_currently_triggered'])),
                'with_history_count' => count(array_filter($items, fn (array $item) => $item['timeline_count'] > 0)),
                'workflow' => [
                    'open_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'open')),
                    'acknowledged_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                    'resolved_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'resolved')),
                ],
                'by_domain' => [
                    'billing_count' => count(array_filter($items, fn (array $item) => $item['domain'] === 'billing')),
                    'finance_count' => count(array_filter($items, fn (array $item) => $item['domain'] === 'finance')),
                ],
            ],
            'items' => array_slice($items, 0, $limit),
        ];
    }

    private function normalizeItem(string $domain, array $item): array
    {
        return [
            'domain' => $domain,
            'queue_key' => sprintf('%s:%s', $domain, $item['code']),
            'code' => $item['code'],
            'title' => $item['title'],
            'severity' => $item['severity'],
            'priority' => $item['priority'],
            'workflow_status' => $item['workflow_status'],
            'is_currently_triggered' => $item['is_currently_triggered'],
            'last_activity_at' => $item['last_activity_at'],
            'last_event_type' => $item['last_event_type'],
            'last_actor' => $item['last_actor'],
            'last_note' => $item['last_note'],
            'timeline_count' => $item['timeline_count'],
            'state' => $item['state'],
            'active_item' => $item['active_item'],
            'detail_path' => sprintf('/reports/operations-escalations/%s/%s', $domain, $item['code']),
            'source_path' => sprintf('/reports/%s/%s', $domain === 'billing' ? 'billing-escalations' : 'finance-escalations', $item['code']),
        ];
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
            throw new HttpException(422, 'Unified escalation history window is invalid.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Unified escalation history limit is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
    }
}
