<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsEscalationReportService
{
    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly BillingEscalationReportService $billingReportService,
        private readonly BillingEscalationHistoryService $billingHistoryService,
        private readonly BillingEscalationMetricsService $billingMetricsService,
        private readonly FinanceEscalationReportService $financeReportService,
        private readonly FinanceEscalationHistoryService $financeHistoryService,
        private readonly FinanceEscalationMetricsService $financeMetricsService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $limit = 10,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertBillingDays($billingDays);
        $this->assertFinanceDaysAhead($financeDaysAhead);
        $this->assertLimit($limit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $baseDate = $this->resolveBaseDate($date);
        $billing = $this->billingReportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $billingDays,
            count(BillingEscalationReportService::KNOWN_CODES),
        );
        $finance = $this->financeReportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $financeDaysAhead,
            count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );
        $billingMetrics = $this->billingMetricsService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $billingDays,
            30,
        );
        $financeMetrics = $this->financeMetricsService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $financeDaysAhead,
            30,
            $staleFollowUpDays,
        );

        $queue = array_merge(
            array_map(fn (array $item) => $this->normalizeBillingItem($item), $billing['items']),
            array_map(fn (array $item) => $this->normalizeFinanceItem($item), $finance['alerts']),
        );

        usort($queue, function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $right['priority'] <=> $left['priority'];
            }

            $severityOrder = ['critical' => 3, 'warning' => 2, 'info' => 1];
            $leftSeverity = $severityOrder[$left['severity']] ?? 0;
            $rightSeverity = $severityOrder[$right['severity']] ?? 0;

            if ($leftSeverity !== $rightSeverity) {
                return $rightSeverity <=> $leftSeverity;
            }

            if ($left['domain'] !== $right['domain']) {
                return strcmp($left['domain'], $right['domain']);
            }

            return strcmp($left['code'], $right['code']);
        });

        return [
            'tenant_id' => $tenantId,
            'window' => [
                'date' => $baseDate->toDateString(),
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'summary' => [
                'open_count' => count($queue),
                'critical_count' => count(array_filter($queue, fn (array $item) => $item['severity'] === 'critical')),
                'warning_count' => count(array_filter($queue, fn (array $item) => $item['severity'] === 'warning')),
                'info_count' => count(array_filter($queue, fn (array $item) => $item['severity'] === 'info')),
                'workflow' => [
                    'open_count' => count(array_filter($queue, fn (array $item) => $item['workflow_status'] === 'open')),
                    'acknowledged_count' => count(array_filter($queue, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                    'resolved_count' => count(array_filter($queue, fn (array $item) => $item['workflow_status'] === 'resolved')),
                ],
                'by_domain' => [
                    'billing_count' => count(array_filter($queue, fn (array $item) => $item['domain'] === 'billing')),
                    'finance_count' => count(array_filter($queue, fn (array $item) => $item['domain'] === 'finance')),
                ],
            ],
            'domain_summaries' => [
                'billing' => [
                    'summary' => $billing['summary'],
                    'metrics' => [
                        'active_count' => $billingMetrics['current_backlog']['active_count'],
                        'acknowledged_count' => $billingMetrics['current_backlog']['acknowledged_count'],
                        'stale_acknowledged_count' => $billingMetrics['current_backlog']['stale_acknowledged_count'],
                        'avg_minutes_from_ack_to_resolve' => $billingMetrics['resolution_sla']['avg_minutes_from_ack_to_resolve'],
                    ],
                ],
                'finance' => [
                    'summary' => $finance['alert_summary'],
                    'metrics' => [
                        'active_count' => $financeMetrics['current_backlog']['active_count'],
                        'acknowledged_count' => $financeMetrics['current_backlog']['acknowledged_count'],
                        'stale_acknowledged_count' => $financeMetrics['current_backlog']['stale_acknowledged_count'],
                        'avg_minutes_from_ack_to_resolve' => $financeMetrics['resolution_sla']['avg_minutes_from_ack_to_resolve'],
                    ],
                ],
            ],
            'queue' => array_slice($queue, 0, $limit),
            'recommended_actions' => array_values(array_unique(array_map(
                fn (array $item) => $item['recommended_action'],
                array_slice($queue, 0, $limit),
            ))),
        ];
    }

    public function detail(
        int $tenantId,
        string $domain,
        string $code,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $historyDays = 30,
        int $activityLimit = 20,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertBillingDays($billingDays);
        $this->assertFinanceDaysAhead($financeDaysAhead);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $domain = trim($domain);

        if (! in_array($domain, ['billing', 'finance'], true)) {
            throw new HttpException(404, 'Escalation domain not found.');
        }

        $detail = $domain === 'billing'
            ? $this->billingHistoryService->detail(
                $tenantId,
                $code,
                $date,
                $billingDays,
                $historyDays,
                $activityLimit,
            )
            : $this->financeHistoryService->detail(
                $tenantId,
                $code,
                $date,
                $financeDaysAhead,
                $historyDays,
                $activityLimit,
                $staleFollowUpDays,
            );

        return array_merge($detail, [
            'domain' => $domain,
            'queue_key' => $domain.':'.$code,
            'actions' => [
                'detail_path' => sprintf('/reports/operations-escalations/%s/%s', $domain, $code),
                'source_path' => sprintf('/reports/%s/%s', $domain === 'billing' ? 'billing-escalations' : 'finance-escalations', $code),
                'acknowledge_path' => sprintf('/reports/%s/%s/acknowledge', $domain === 'billing' ? 'billing-escalations' : 'finance-escalations', $code),
                'resolve_path' => sprintf('/reports/%s/%s/resolve', $domain === 'billing' ? 'billing-escalations' : 'finance-escalations', $code),
            ],
        ]);
    }

    private function normalizeBillingItem(array $item): array
    {
        return [
            'domain' => 'billing',
            'queue_key' => 'billing:'.$item['code'],
            'code' => $item['code'],
            'severity' => $item['severity'],
            'priority' => $item['priority'],
            'title' => $item['title'],
            'message' => $item['message'],
            'recommended_action' => $item['recommended_action'],
            'workflow_status' => $item['workflow_status'],
            'state' => $item['state'],
            'is_currently_triggered' => $item['is_currently_triggered'],
            'metric_snapshot' => $item['metric_snapshot'],
            'detail_path' => '/reports/operations-escalations/billing/'.$item['code'],
            'source_path' => '/reports/billing-escalations/'.$item['code'],
            'acknowledge_path' => '/reports/billing-escalations/'.$item['code'].'/acknowledge',
            'resolve_path' => '/reports/billing-escalations/'.$item['code'].'/resolve',
        ];
    }

    private function normalizeFinanceItem(array $item): array
    {
        return [
            'domain' => 'finance',
            'queue_key' => 'finance:'.$item['code'],
            'code' => $item['code'],
            'severity' => $item['severity'],
            'priority' => $item['priority'],
            'title' => $item['title'],
            'message' => $item['message'],
            'recommended_action' => $item['recommended_action'],
            'workflow_status' => $item['workflow_status'],
            'state' => $item['state'],
            'is_currently_triggered' => $item['is_currently_triggered'],
            'metric_snapshot' => $item['metric_snapshot'],
            'detail_path' => '/reports/operations-escalations/finance/'.$item['code'],
            'source_path' => '/reports/finance-escalations/'.$item['code'],
            'acknowledge_path' => '/reports/finance-escalations/'.$item['code'].'/acknowledge',
            'resolve_path' => '/reports/finance-escalations/'.$item['code'].'/resolve',
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

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Unified escalation limit is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
    }
}
