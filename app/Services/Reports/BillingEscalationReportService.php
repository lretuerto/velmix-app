<?php

namespace App\Services\Reports;

use App\Services\Billing\BillingProviderMetricsService;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingEscalationReportService
{
    public const KNOWN_CODES = [
        'billing.health_stale',
        'billing.failed_backlog',
        'billing.pending_backlog',
        'billing.failure_rate_high',
        'billing.acceptance_rate_low',
        'billing.replay_backlog',
        'billing.mixed_environments',
    ];

    public function __construct(
        private readonly BillingProviderMetricsService $providerMetrics,
        private readonly BillingEscalationStateService $stateService,
    ) {
    }

    public function summary(int $tenantId, ?string $date = null, int $days = 7, int $limit = 10): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($days <= 0 || $days > 14) {
            throw new HttpException(422, 'Escalation window is invalid.');
        }

        if ($limit <= 0 || $limit > 20) {
            throw new HttpException(422, 'Escalation limit is invalid.');
        }

        $metrics = $this->providerMetrics->summary($tenantId, $date, $days, $limit);
        $statesByCode = $this->stateService->listByCode($tenantId);
        $escalations = array_map(
            fn (array $item) => $this->mergeState($item, $statesByCode[$item['code']] ?? null),
            $this->buildEscalations($metrics),
        );

        return [
            'tenant_id' => $tenantId,
            'window' => $metrics['window'],
            'summary' => [
                'open_count' => count($escalations),
                'critical_count' => count(array_filter($escalations, fn (array $item) => $item['severity'] === 'critical')),
                'warning_count' => count(array_filter($escalations, fn (array $item) => $item['severity'] === 'warning')),
                'info_count' => count(array_filter($escalations, fn (array $item) => $item['severity'] === 'info')),
                'workflow' => [
                    'open_count' => count(array_filter($escalations, fn (array $item) => $item['workflow_status'] === 'open')),
                    'acknowledged_count' => count(array_filter($escalations, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                    'resolved_count' => count(array_filter($escalations, fn (array $item) => $item['workflow_status'] === 'resolved')),
                ],
            ],
            'items' => array_slice($escalations, 0, $limit),
            'recommended_actions' => array_values(array_unique(array_map(
                fn (array $item) => $item['recommended_action'],
                array_slice($escalations, 0, $limit),
            ))),
        ];
    }

    private function buildEscalations(array $metrics): array
    {
        $items = [];
        $checkedAt = $metrics['health']['checked_at'] !== null
            ? CarbonImmutable::parse((string) $metrics['health']['checked_at'])
            : null;
        $hoursSinceHealthCheck = $checkedAt !== null
            ? round($checkedAt->diffInSeconds(CarbonImmutable::now()) / 3600, 2)
            : null;
        $failureRate = (float) ($metrics['performance']['failure_rate'] ?? 0.0);
        $acceptanceRate = (float) ($metrics['performance']['acceptance_rate'] ?? 0.0);
        $pendingCount = (int) ($metrics['queue']['pending_count'] ?? 0);
        $failedCount = (int) ($metrics['queue']['failed_count'] ?? 0);
        $oldestPendingAgeMinutes = $metrics['queue']['oldest_pending_age_minutes'];
        $replayPendingCount = (int) ($metrics['replays']['pending_count'] ?? 0);
        $environments = collect($metrics['by_provider_environment'] ?? []);

        if ($metrics['health']['is_stale']) {
            $items[] = $this->makeItem(
                code: 'billing.health_stale',
                severity: $hoursSinceHealthCheck !== null && $hoursSinceHealthCheck >= 72 ? 'critical' : 'warning',
                priority: $hoursSinceHealthCheck !== null && $hoursSinceHealthCheck >= 72 ? 100 : 80,
                title: 'Health check del provider desactualizado',
                message: 'El snapshot de salud del provider esta vencido o no existe.',
                recommendedAction: 'Ejecutar `POST /billing/provider-profile/check` y validar credenciales/provider environment.',
                metricSnapshot: [
                    'health_status' => $metrics['health']['current_status'],
                    'checked_at' => $metrics['health']['checked_at'],
                    'hours_since_check' => $hoursSinceHealthCheck,
                ],
            );
        }

        if ($failedCount > 0) {
            $items[] = $this->makeItem(
                code: 'billing.failed_backlog',
                severity: 'critical',
                priority: 95,
                title: 'Eventos fallidos pendientes de retry',
                message: 'Existen eventos fallidos en el outbox que requieren intervencion.',
                recommendedAction: 'Revisar `/billing/outbox/{event}/attempts` y ejecutar `POST /billing/outbox/{event}/retry` en los fallidos.',
                metricSnapshot: [
                    'failed_count' => $failedCount,
                    'latest_attempt' => $metrics['queue']['latest_attempt'],
                ],
            );
        }

        if ($pendingCount > 0 && $oldestPendingAgeMinutes !== null && $oldestPendingAgeMinutes >= 30) {
            $items[] = $this->makeItem(
                code: 'billing.pending_backlog',
                severity: $oldestPendingAgeMinutes >= 240 ? 'critical' : 'warning',
                priority: $oldestPendingAgeMinutes >= 240 ? 90 : 70,
                title: 'Backlog pendiente de billing',
                message: 'Hay eventos pendientes con antiguedad operativa significativa.',
                recommendedAction: 'Despachar el outbox con `POST /billing/outbox/dispatch` o `composer run velmix:outbox`.',
                metricSnapshot: [
                    'pending_count' => $pendingCount,
                    'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
                    'oldest_pending' => $metrics['queue']['oldest_pending'],
                ],
            );
        }

        if ($failureRate >= 20.0) {
            $items[] = $this->makeItem(
                code: 'billing.failure_rate_high',
                severity: $failureRate >= 50.0 ? 'critical' : 'warning',
                priority: $failureRate >= 50.0 ? 88 : 68,
                title: 'Tasa de fallos de billing elevada',
                message: 'La ventana actual muestra una tasa de fallos por encima del umbral esperado.',
                recommendedAction: 'Inspeccionar fallos recientes y validar provider/environment antes de seguir despachando.',
                metricSnapshot: [
                    'failure_rate' => $failureRate,
                    'event_count' => $metrics['performance']['event_count'],
                    'failed_event_count' => $metrics['performance']['failed_event_count'],
                ],
            );
        }

        if (($metrics['performance']['event_count'] ?? 0) >= 3 && $acceptanceRate < 80.0) {
            $items[] = $this->makeItem(
                code: 'billing.acceptance_rate_low',
                severity: $acceptanceRate < 50.0 ? 'critical' : 'warning',
                priority: $acceptanceRate < 50.0 ? 86 : 66,
                title: 'Acceptance rate por debajo del objetivo',
                message: 'La aceptacion del provider cayo debajo del objetivo operativo.',
                recommendedAction: 'Comparar `/billing/provider-metrics` con `/billing/outbox/provider-trace` para identificar el environment afectado.',
                metricSnapshot: [
                    'acceptance_rate' => $acceptanceRate,
                    'accepted_event_count' => $metrics['performance']['accepted_event_count'],
                    'event_count' => $metrics['performance']['event_count'],
                ],
            );
        }

        if ($replayPendingCount > 0) {
            $items[] = $this->makeItem(
                code: 'billing.replay_backlog',
                severity: $replayPendingCount >= 3 ? 'warning' : 'info',
                priority: $replayPendingCount >= 3 ? 60 : 40,
                title: 'Replays pendientes de billing',
                message: 'Hay documentos reemitidos que todavia no completan su nuevo despacho.',
                recommendedAction: 'Priorizar los `replay` en `/billing/outbox/{event}/lineage` y despachar nuevamente el outbox.',
                metricSnapshot: [
                    'replay_pending_count' => $replayPendingCount,
                    'replay_created_count' => $metrics['replays']['created_count'],
                ],
            );
        }

        $observedEnvironments = collect([$metrics['provider_profile']['environment'] ?? null])
            ->merge($environments->pluck('provider_environment'))
            ->filter()
            ->unique()
            ->values();

        if ($observedEnvironments->count() > 1) {
            $items[] = $this->makeItem(
                code: 'billing.mixed_environments',
                severity: 'info',
                priority: 35,
                title: 'Actividad mixta entre sandbox y live',
                message: 'La ventana consultada tiene intentos en multiples environments.',
                recommendedAction: 'Verificar que el tenant este operando en el environment correcto antes de regenerar o replay de payloads.',
                metricSnapshot: [
                    'environments' => $observedEnvironments->all(),
                ],
            );
        }

        usort($items, function (array $left, array $right): int {
            if ($left['priority'] !== $right['priority']) {
                return $right['priority'] <=> $left['priority'];
            }

            return $left['code'] <=> $right['code'];
        });

        return $items;
    }

    private function mergeState(array $item, ?array $state): array
    {
        $item['workflow_status'] = $state['status'] ?? 'open';
        $item['state'] = $state;
        $item['is_currently_triggered'] = true;

        return $item;
    }

    private function makeItem(
        string $code,
        string $severity,
        int $priority,
        string $title,
        string $message,
        string $recommendedAction,
        array $metricSnapshot,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'priority' => $priority,
            'title' => $title,
            'message' => $message,
            'recommended_action' => $recommendedAction,
            'metric_snapshot' => $metricSnapshot,
        ];
    }
}
