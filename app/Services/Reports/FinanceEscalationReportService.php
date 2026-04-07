<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceEscalationReportService
{
    private const MAX_LIMIT = 20;
    private const STALE_ACKNOWLEDGED_HOURS = 24;

    public function __construct(
        private readonly FinanceOperationsReportService $reportService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $daysAhead = 7,
        int $limit = 10,
        int $staleFollowUpDays = 3,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($daysAhead < 1 || $daysAhead > 30) {
            throw new HttpException(422, 'days_ahead is invalid.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Finance escalation limit is invalid.');
        }

        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }

        $baseDate = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $items = array_map(
            fn (array $item) => $this->buildItem($item, $baseDate, $staleFollowUpDays),
            $this->reportService->workflowItems($tenantId, $baseDate->toDateString(), $daysAhead),
        );

        usort($items, function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $right['priority'] <=> $left['priority'];
            }

            if ($left['outstanding_amount'] !== $right['outstanding_amount']) {
                return $right['outstanding_amount'] <=> $left['outstanding_amount'];
            }

            return strcmp((string) $left['reference'], (string) $right['reference']);
        });

        $visibleItems = array_slice($items, 0, $limit);

        return [
            'tenant_id' => $tenantId,
            'window' => [
                'date' => $baseDate->toDateString(),
                'days_ahead' => $daysAhead,
                'stale_follow_up_days' => $staleFollowUpDays,
            ],
            'summary' => [
                'open_count' => count($items),
                'critical_count' => count(array_filter($items, fn (array $item) => $item['severity'] === 'critical')),
                'warning_count' => count(array_filter($items, fn (array $item) => $item['severity'] === 'warning')),
                'info_count' => count(array_filter($items, fn (array $item) => $item['severity'] === 'info')),
                'workflow' => [
                    'open_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'open')),
                    'acknowledged_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                    'resolved_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'resolved')),
                ],
                'by_kind' => [
                    'receivable_count' => count(array_filter($items, fn (array $item) => $item['kind'] === 'receivable')),
                    'payable_count' => count(array_filter($items, fn (array $item) => $item['kind'] === 'payable')),
                ],
                'flags' => [
                    'broken_promise_count' => count(array_filter($items, fn (array $item) => $item['flags']['broken_promise'])),
                    'stale_acknowledged_count' => count(array_filter($items, fn (array $item) => $item['flags']['stale_acknowledged'])),
                    'stale_follow_up_count' => count(array_filter($items, fn (array $item) => $item['flags']['stale_follow_up'])),
                    'missing_follow_up_count' => count(array_filter($items, fn (array $item) => $item['flags']['missing_follow_up'])),
                ],
            ],
            'items' => $visibleItems,
            'recommended_actions' => array_values(array_unique(array_map(
                fn (array $item) => $item['recommended_action'],
                $visibleItems,
            ))),
        ];
    }

    private function buildItem(array $item, CarbonImmutable $baseDate, int $staleFollowUpDays): array
    {
        $flags = $this->flags($item, $baseDate, $staleFollowUpDays);
        [$severity, $priority] = $this->severityAndPriority($item, $flags);
        $title = $this->title($item, $flags);
        $message = $this->message($item, $flags);
        $recommendedAction = $this->recommendedAction($item, $flags);

        return [
            'entity_key' => $item['entity_key'],
            'kind' => $item['kind'],
            'entity_id' => $item['entity_id'],
            'reference' => $item['reference'],
            'entity_name' => $item['entity_name'],
            'outstanding_amount' => round((float) $item['outstanding_amount'], 2),
            'due_at' => $item['due_at'],
            'days_overdue' => (int) $item['days_overdue'],
            'days_until_due' => (int) $item['days_until_due'],
            'promise_status' => $item['promise_status'],
            'workflow_status' => $item['workflow_status'],
            'escalation_level' => $item['escalation_level'],
            'severity' => $severity,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'recommended_action' => $recommendedAction,
            'flags' => $flags,
            'latest_follow_up' => $item['latest_follow_up'],
            'state' => $item['state'],
        ];
    }

    private function flags(array $item, CarbonImmutable $baseDate, int $staleFollowUpDays): array
    {
        $createdAt = $item['latest_follow_up']['created_at'] ?? null;
        $stateTouchedAt = $item['state']['updated_at'] ?? $item['state']['acknowledged_at'] ?? $item['state']['resolved_at'] ?? null;
        $effectiveFollowUpAt = $stateTouchedAt ?? $createdAt;
        $acknowledgedAt = $item['state']['acknowledged_at'] ?? null;

        return [
            'broken_promise' => $item['promise_status'] === 'broken',
            'stale_acknowledged' => $item['workflow_status'] === 'acknowledged'
                && $acknowledgedAt !== null
                && CarbonImmutable::parse($acknowledgedAt)->lt($baseDate->endOfDay()->subHours(self::STALE_ACKNOWLEDGED_HOURS)),
            'stale_follow_up' => $effectiveFollowUpAt !== null
                && CarbonImmutable::parse($effectiveFollowUpAt)->lt($baseDate->subDays($staleFollowUpDays)),
            'missing_follow_up' => $effectiveFollowUpAt === null,
            'overdue' => (int) $item['days_overdue'] > 0,
            'severely_overdue' => (int) $item['days_overdue'] >= 30,
        ];
    }

    private function severityAndPriority(array $item, array $flags): array
    {
        if ($flags['broken_promise'] || $flags['stale_acknowledged'] || $flags['severely_overdue']) {
            return ['critical', 100 + min((int) $item['days_overdue'], 60)];
        }

        if ($flags['overdue'] || $flags['stale_follow_up'] || ($flags['missing_follow_up'] && (int) $item['days_until_due'] <= 3)) {
            return ['warning', 70 + min(max((int) $item['days_overdue'], 0), 30)];
        }

        return ['info', 40 + max(3 - max((int) $item['days_until_due'], 0), 0)];
    }

    private function title(array $item, array $flags): string
    {
        if ($flags['stale_acknowledged']) {
            return 'Seguimiento financiero acknowledged envejecido';
        }

        if ($flags['broken_promise']) {
            return $item['kind'] === 'receivable'
                ? 'Cobranza con promesa rota'
                : 'Pago a proveedor con compromiso incumplido';
        }

        if ($flags['overdue']) {
            return $item['kind'] === 'receivable'
                ? 'Cuenta por cobrar vencida'
                : 'Cuenta por pagar vencida';
        }

        if ($flags['missing_follow_up']) {
            return 'Prioridad financiera sin seguimiento';
        }

        return 'Prioridad financiera activa';
    }

    private function message(array $item, array $flags): string
    {
        $reference = (string) $item['reference'];
        $name = (string) $item['entity_name'];

        if ($flags['stale_acknowledged']) {
            return sprintf('El caso %s de %s sigue acknowledged sin resolucion reciente.', $reference, $name);
        }

        if ($flags['broken_promise']) {
            return sprintf('El compromiso registrado para %s de %s ya se incumplio.', $reference, $name);
        }

        if ($flags['overdue']) {
            return sprintf('%s de %s acumula %d dias de atraso.', $reference, $name, (int) $item['days_overdue']);
        }

        if ($flags['missing_follow_up']) {
            return sprintf('%s de %s entra en la cola priorizada sin nota operativa registrada.', $reference, $name);
        }

        return sprintf('%s de %s sigue en la cola operativa financiera.', $reference, $name);
    }

    private function recommendedAction(array $item, array $flags): string
    {
        if ($flags['stale_acknowledged']) {
            return 'Resolver o actualizar el seguimiento del caso y registrar una nota operativa nueva.';
        }

        if ($flags['broken_promise']) {
            return $item['kind'] === 'receivable'
                ? 'Contactar al cliente, registrar una nueva promesa o cobrar parcialmente y documentar el seguimiento.'
                : 'Coordinar una nueva fecha real de pago con el proveedor y registrar la actualizacion del compromiso.';
        }

        if ($flags['missing_follow_up']) {
            return 'Registrar un follow-up inicial para dejar contexto operativo antes del siguiente vencimiento.';
        }

        if ($flags['overdue']) {
            return $item['kind'] === 'receivable'
                ? 'Priorizar cobranza y validar bloqueo de crédito del cliente si sigue en mora.'
                : 'Priorizar programación de pago o renegociación con el proveedor.';
        }

        return 'Mantener monitoreo de la prioridad financiera y actualizar seguimiento si cambia el riesgo.';
    }
}
