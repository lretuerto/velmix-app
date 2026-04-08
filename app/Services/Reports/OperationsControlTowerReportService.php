<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperationsControlTowerReportService
{
    public function __construct(
        private readonly DailyReportService $dailyReport,
        private readonly BillingOperationsReportService $billingOperations,
        private readonly FinanceOperationsReportService $financeOperations,
        private readonly OperationsEscalationReportService $operationsEscalations,
        private readonly OperationsEscalationMetricsService $operationsEscalationMetrics,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $billingDays = 7,
        int $financeDaysAhead = 7,
        int $priorityLimit = 5,
        int $failureLimit = 5,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertBillingDays($billingDays);
        $this->assertFinanceDaysAhead($financeDaysAhead);
        $this->assertPriorityLimit($priorityLimit);
        $this->assertFailureLimit($failureLimit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $baseDate = $this->resolveBaseDate($date);

        $daily = $this->dailyReport->summary($tenantId, $baseDate->toDateString());
        $billing = $this->billingOperations->summary($tenantId, $baseDate->toDateString(), $billingDays, $failureLimit);
        $finance = $this->financeOperations->summary($tenantId, $baseDate->toDateString(), $financeDaysAhead, $priorityLimit, $staleFollowUpDays);
        $operations = $this->operationsEscalations->summary($tenantId, $baseDate->toDateString(), $billingDays, $financeDaysAhead, $priorityLimit, $staleFollowUpDays);
        $operationsMetrics = $this->operationsEscalationMetrics->summary($tenantId, $baseDate->toDateString(), $billingDays, $financeDaysAhead, 30, $staleFollowUpDays);

        $paths = [
            'daily' => sprintf('/reports/daily?date=%s', $baseDate->toDateString()),
            'billing_operations' => sprintf('/reports/billing-operations?date=%s&days=%d&failure_limit=%d', $baseDate->toDateString(), $billingDays, $failureLimit),
            'finance_operations' => sprintf('/reports/finance-operations?date=%s&days_ahead=%d&limit=%d&stale_follow_up_days=%d', $baseDate->toDateString(), $financeDaysAhead, $priorityLimit, $staleFollowUpDays),
            'operations_escalations' => sprintf('/reports/operations-escalations?date=%s&billing_days=%d&finance_days_ahead=%d&limit=%d&stale_follow_up_days=%d', $baseDate->toDateString(), $billingDays, $financeDaysAhead, $priorityLimit, $staleFollowUpDays),
        ];

        $healthGates = [
            'sales_cash' => $this->salesCashGate($daily, $paths['daily']),
            'billing' => $this->billingGate($billing, $paths['billing_operations']),
            'finance' => $this->financeGate($finance, $paths['finance_operations']),
            'operations' => $this->operationsGate($operations, $operationsMetrics, $paths['operations_escalations']),
        ];

        $overallStatus = $this->maxStatus(array_map(
            fn (array $gate) => (string) $gate['status'],
            $healthGates,
        ));

        return [
            'tenant_id' => $tenantId,
            'date' => $baseDate->toDateString(),
            'windows' => [
                'billing_days' => $billingDays,
                'finance_days_ahead' => $financeDaysAhead,
                'priority_limit' => $priorityLimit,
                'failure_limit' => $failureLimit,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'paths' => $paths,
            'executive_summary' => [
                'overall_status' => $overallStatus,
                'critical_gate_count' => count(array_filter($healthGates, fn (array $gate) => $gate['status'] === 'critical')),
                'warning_gate_count' => count(array_filter($healthGates, fn (array $gate) => $gate['status'] === 'warning')),
                'sales_completed_total' => $daily['sales']['completed_total'],
                'collections_total' => $daily['collections']['total_amount'],
                'cash_discrepancy_total' => $daily['cash']['discrepancy_total'],
                'billing_pending_backlog_count' => $billing['executive_summary']['pending_backlog_count'],
                'billing_failed_backlog_count' => $billing['executive_summary']['failed_backlog_count'],
                'finance_overdue_total' => $finance['combined']['overdue_total'],
                'finance_broken_promise_count' => $finance['combined']['broken_promise_count'],
                'operations_open_alert_count' => $operations['summary']['open_count'],
                'operations_critical_alert_count' => $operations['summary']['critical_count'],
            ],
            'health_gates' => $healthGates,
            'action_center' => [
                'operations_queue' => $operations['queue'],
                'finance_priority_queue' => $finance['priority_queue'],
                'billing_recent_failures' => $billing['recent_failures'],
                'recommended_actions' => $this->recommendedActions($operations, $healthGates),
            ],
            'slices' => [
                'daily' => [
                    'sales' => [
                        'completed_count' => $daily['sales']['completed_count'],
                        'completed_total' => $daily['sales']['completed_total'],
                        'cancelled_count' => $daily['sales']['cancelled_count'],
                    ],
                    'collections' => [
                        'payment_count' => $daily['collections']['payment_count'],
                        'total_amount' => $daily['collections']['total_amount'],
                    ],
                    'cash' => [
                        'opened_count' => $daily['cash']['opened_count'],
                        'closed_count' => $daily['cash']['closed_count'],
                        'discrepancy_total' => $daily['cash']['discrepancy_total'],
                        'refund_out_total' => $daily['cash']['refund_out_total'],
                    ],
                ],
                'billing' => [
                    'executive_summary' => $billing['executive_summary'],
                    'backlog_aging' => $billing['backlog_aging'],
                    'alerts' => $billing['alerts'],
                ],
                'finance' => [
                    'combined' => $finance['combined'],
                    'workflow' => $finance['workflow'],
                ],
                'operations' => [
                    'summary' => $operations['summary'],
                    'workflow_metrics' => [
                        'active_count' => $operationsMetrics['current_backlog']['active_count'],
                        'acknowledged_count' => $operationsMetrics['current_backlog']['acknowledged_count'],
                        'stale_acknowledged_count' => $operationsMetrics['current_backlog']['stale_acknowledged_count'],
                        'acknowledged_event_count' => $operationsMetrics['workflow_events']['acknowledged_event_count'],
                        'resolved_event_count' => $operationsMetrics['workflow_events']['resolved_event_count'],
                        'avg_minutes_from_ack_to_resolve' => $operationsMetrics['resolution_sla']['avg_minutes_from_ack_to_resolve'],
                    ],
                ],
            ],
        ];
    }

    private function salesCashGate(array $daily, string $path): array
    {
        $discrepancy = (float) ($daily['cash']['discrepancy_total'] ?? 0);
        $refunds = (float) ($daily['cash']['refund_out_total'] ?? 0);

        if (abs($discrepancy) > 0) {
            return [
                'status' => 'warning',
                'label' => 'Caja del dia',
                'reason' => 'La caja cerró con discrepancia y requiere arqueo.',
                'action' => 'Revisar cierre de caja y denominaciones del turno.',
                'path' => $path,
                'metric_snapshot' => [
                    'discrepancy_total' => round($discrepancy, 2),
                    'refund_out_total' => round($refunds, 2),
                ],
            ];
        }

        if ($refunds > 0) {
            return [
                'status' => 'warning',
                'label' => 'Caja del dia',
                'reason' => 'Hubo devoluciones de efectivo y conviene revisar el corte.',
                'action' => 'Validar notas de crédito y salidas de efectivo del día.',
                'path' => $path,
                'metric_snapshot' => [
                    'discrepancy_total' => round($discrepancy, 2),
                    'refund_out_total' => round($refunds, 2),
                ],
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'Caja del dia',
            'reason' => 'La operación diaria de caja no muestra alertas.',
            'action' => null,
            'path' => $path,
            'metric_snapshot' => [
                'discrepancy_total' => round($discrepancy, 2),
                'refund_out_total' => round($refunds, 2),
            ],
        ];
    }

    private function billingGate(array $billing, string $path): array
    {
        $summary = $billing['executive_summary'];

        if ((int) $summary['failed_backlog_count'] > 0 || (bool) $summary['health_is_stale']) {
            return [
                'status' => 'critical',
                'label' => 'Billing',
                'reason' => 'Billing tiene backlog fallido o health stale.',
                'action' => 'Priorizar retry de outbox y validar health del provider.',
                'path' => $path,
                'metric_snapshot' => [
                    'failed_backlog_count' => (int) $summary['failed_backlog_count'],
                    'pending_backlog_count' => (int) $summary['pending_backlog_count'],
                    'health_is_stale' => (bool) $summary['health_is_stale'],
                    'acceptance_rate' => (float) $summary['acceptance_rate'],
                ],
            ];
        }

        if ((int) $summary['pending_backlog_count'] > 0 || (int) $summary['replay_backlog_count'] > 0 || (int) $summary['recent_failure_count'] > 0) {
            return [
                'status' => 'warning',
                'label' => 'Billing',
                'reason' => 'Billing mantiene pendientes o replays por resolver.',
                'action' => 'Monitorear la cola y revisar los últimos fallos del provider.',
                'path' => $path,
                'metric_snapshot' => [
                    'failed_backlog_count' => (int) $summary['failed_backlog_count'],
                    'pending_backlog_count' => (int) $summary['pending_backlog_count'],
                    'replay_backlog_count' => (int) $summary['replay_backlog_count'],
                    'acceptance_rate' => (float) $summary['acceptance_rate'],
                ],
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'Billing',
            'reason' => 'Billing no muestra señales operativas de riesgo.',
            'action' => null,
            'path' => $path,
            'metric_snapshot' => [
                'failed_backlog_count' => (int) $summary['failed_backlog_count'],
                'pending_backlog_count' => (int) $summary['pending_backlog_count'],
                'replay_backlog_count' => (int) $summary['replay_backlog_count'],
                'acceptance_rate' => (float) $summary['acceptance_rate'],
            ],
        ];
    }

    private function financeGate(array $finance, string $path): array
    {
        $combined = $finance['combined'];

        if ((int) $combined['broken_promise_count'] > 0 || ((float) $combined['overdue_total'] > 0 && (int) $combined['stale_follow_up_count'] > 0)) {
            return [
                'status' => 'critical',
                'label' => 'Finanzas',
                'reason' => 'Hay vencidos con promesas rotas o seguimiento envejecido.',
                'action' => 'Atender la cola financiera priorizada y actualizar follow-ups.',
                'path' => $path,
                'metric_snapshot' => [
                    'overdue_total' => (float) $combined['overdue_total'],
                    'broken_promise_count' => (int) $combined['broken_promise_count'],
                    'stale_follow_up_count' => (int) $combined['stale_follow_up_count'],
                    'missing_follow_up_count' => (int) $combined['missing_follow_up_count'],
                ],
            ];
        }

        if ((float) $combined['overdue_total'] > 0 || (int) $combined['missing_follow_up_count'] > 0 || (int) $combined['stale_follow_up_count'] > 0) {
            return [
                'status' => 'warning',
                'label' => 'Finanzas',
                'reason' => 'La exposición financiera requiere seguimiento cercano.',
                'action' => 'Revisar vencimientos y promesas pendientes.',
                'path' => $path,
                'metric_snapshot' => [
                    'overdue_total' => (float) $combined['overdue_total'],
                    'broken_promise_count' => (int) $combined['broken_promise_count'],
                    'stale_follow_up_count' => (int) $combined['stale_follow_up_count'],
                    'missing_follow_up_count' => (int) $combined['missing_follow_up_count'],
                ],
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'Finanzas',
            'reason' => 'Cobranza y pagos no muestran alertas operativas relevantes.',
            'action' => null,
            'path' => $path,
            'metric_snapshot' => [
                'overdue_total' => (float) $combined['overdue_total'],
                'broken_promise_count' => (int) $combined['broken_promise_count'],
                'stale_follow_up_count' => (int) $combined['stale_follow_up_count'],
                'missing_follow_up_count' => (int) $combined['missing_follow_up_count'],
            ],
        ];
    }

    private function operationsGate(array $operations, array $metrics, string $path): array
    {
        $summary = $operations['summary'];
        $workflowMetrics = $metrics['current_backlog'];

        if ((int) $summary['critical_count'] > 0) {
            return [
                'status' => 'critical',
                'label' => 'Escalaciones operativas',
                'reason' => 'La cola unificada tiene alertas críticas activas.',
                'action' => 'Atender primero la cola cross-domain y resolver los códigos críticos.',
                'path' => $path,
                'metric_snapshot' => [
                    'open_count' => (int) $summary['open_count'],
                    'critical_count' => (int) $summary['critical_count'],
                    'acknowledged_count' => (int) $workflowMetrics['acknowledged_count'],
                    'stale_acknowledged_count' => (int) $workflowMetrics['stale_acknowledged_count'],
                ],
            ];
        }

        if ((int) $summary['open_count'] > 0 || (int) $workflowMetrics['stale_acknowledged_count'] > 0) {
            return [
                'status' => 'warning',
                'label' => 'Escalaciones operativas',
                'reason' => 'La cola unificada mantiene alertas abiertas o acknowledged envejecidas.',
                'action' => 'Revisar el workflow unificado y cerrar pendientes manuales.',
                'path' => $path,
                'metric_snapshot' => [
                    'open_count' => (int) $summary['open_count'],
                    'critical_count' => (int) $summary['critical_count'],
                    'acknowledged_count' => (int) $workflowMetrics['acknowledged_count'],
                    'stale_acknowledged_count' => (int) $workflowMetrics['stale_acknowledged_count'],
                ],
            ];
        }

        return [
            'status' => 'ok',
            'label' => 'Escalaciones operativas',
            'reason' => 'La cola cross-domain está bajo control.',
            'action' => null,
            'path' => $path,
            'metric_snapshot' => [
                'open_count' => (int) $summary['open_count'],
                'critical_count' => (int) $summary['critical_count'],
                'acknowledged_count' => (int) $workflowMetrics['acknowledged_count'],
                'stale_acknowledged_count' => (int) $workflowMetrics['stale_acknowledged_count'],
            ],
        ];
    }

    private function recommendedActions(array $operations, array $healthGates): array
    {
        $actions = $operations['recommended_actions'] ?? [];

        foreach ($healthGates as $gate) {
            if (($gate['status'] ?? 'ok') !== 'ok' && $gate['action'] !== null) {
                $actions[] = $gate['action'];
            }
        }

        return array_values(array_unique(array_filter($actions)));
    }

    private function maxStatus(array $statuses): string
    {
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        $max = 'ok';

        foreach ($statuses as $status) {
            if (($rank[$status] ?? 0) > ($rank[$max] ?? 0)) {
                $max = $status;
            }
        }

        return $max;
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

    private function assertPriorityLimit(int $priorityLimit): void
    {
        if ($priorityLimit < 1 || $priorityLimit > 20) {
            throw new HttpException(422, 'priority_limit is invalid.');
        }
    }

    private function assertFailureLimit(int $failureLimit): void
    {
        if ($failureLimit < 1 || $failureLimit > 20) {
            throw new HttpException(422, 'failure_limit is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
    }
}
