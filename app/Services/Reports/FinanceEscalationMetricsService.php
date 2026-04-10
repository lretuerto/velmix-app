<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceEscalationMetricsService
{
    private const MAX_HISTORY_DAYS = 90;
    private const STALE_ACKNOWLEDGED_HOURS = 24;

    public function __construct(
        private readonly FinanceEscalationReportService $reportService,
    ) {
    }

    public function summary(
        int $tenantId,
        ?string $date = null,
        int $daysAhead = 7,
        int $historyDays = 30,
        int $staleFollowUpDays = 3,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Finance escalation metrics history window is invalid.');
        }

        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }

        $baseDate = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $referenceTime = $baseDate->endOfDay();
        $activeReport = $this->reportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $daysAhead,
            count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );
        $activeAlerts = collect($activeReport['alerts']);
        $historyWindow = $this->historyWindow($historyDays, $baseDate);
        $activities = $this->fetchActivities($tenantId, $historyWindow['start_at'], $historyWindow['end_at']);
        $resolutionPairs = $this->buildResolutionPairs($activities);
        $recentResolutions = $resolutionPairs
            ->sortByDesc('resolved_at')
            ->take(5)
            ->values()
            ->map(function (array $pair) use ($activeAlerts) {
                $activeItem = $activeAlerts->firstWhere('code', $pair['code']);

                return [
                    'code' => $pair['code'],
                    'title' => $activeItem['title'] ?? $this->titleForCode($pair['code']),
                    'acknowledged_at' => $pair['acknowledged_at'],
                    'resolved_at' => $pair['resolved_at'],
                    'minutes_from_ack_to_resolve' => $pair['minutes_from_ack_to_resolve'],
                    'resolved_by' => $pair['resolved_by'],
                    'resolution_note' => $pair['resolution_note'],
                    'is_currently_triggered' => $activeItem !== null,
                ];
            })
            ->all();

        $staleAcknowledged = $activeAlerts
            ->filter(function (array $item) use ($referenceTime) {
                $acknowledgedAt = $item['state']['acknowledged_at'] ?? null;

                if ($item['workflow_status'] !== 'acknowledged' || $acknowledgedAt === null) {
                    return false;
                }

                return CarbonImmutable::parse($acknowledgedAt)
                    ->lt($referenceTime->subHours(self::STALE_ACKNOWLEDGED_HOURS));
            })
            ->values();

        $acknowledgedAges = $activeAlerts
            ->filter(fn (array $item) => $item['workflow_status'] === 'acknowledged' && ($item['state']['acknowledged_at'] ?? null) !== null)
            ->map(fn (array $item) => $this->diffMinutes(
                (string) $item['state']['acknowledged_at'],
                $referenceTime->toDateTimeString(),
            ))
            ->values();

        return [
            'tenant_id' => $tenantId,
            'active_window' => $activeReport['window'],
            'history_window' => [
                'days' => $historyWindow['days'],
                'start_date' => $historyWindow['start_date'],
                'end_date' => $historyWindow['end_date'],
            ],
            'current_backlog' => [
                'active_count' => $activeAlerts->count(),
                'open_count' => $activeAlerts->where('workflow_status', 'open')->count(),
                'acknowledged_count' => $activeAlerts->where('workflow_status', 'acknowledged')->count(),
                'resolved_but_active_count' => $activeAlerts->where('workflow_status', 'resolved')->count(),
                'critical_count' => $activeAlerts->where('severity', 'critical')->count(),
                'warning_count' => $activeAlerts->where('severity', 'warning')->count(),
                'info_count' => $activeAlerts->where('severity', 'info')->count(),
                'stale_acknowledged_count' => $staleAcknowledged->count(),
                'oldest_acknowledged_age_minutes' => $acknowledgedAges->isNotEmpty()
                    ? round((float) $acknowledgedAges->max(), 2)
                    : null,
            ],
            'workflow_events' => [
                'acknowledged_event_count' => $activities->where('event_type', 'finance.escalation.acknowledged')->count(),
                'resolved_event_count' => $activities->where('event_type', 'finance.escalation.resolved')->count(),
                'latest_acknowledged_at' => $activities->firstWhere('event_type', 'finance.escalation.acknowledged')['occurred_at'] ?? null,
                'latest_resolved_at' => $activities->firstWhere('event_type', 'finance.escalation.resolved')['occurred_at'] ?? null,
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
            'current_high_priority_codes' => $activeAlerts
                ->sortByDesc('priority')
                ->take(5)
                ->values()
                ->map(fn (array $item) => [
                    'code' => $item['code'],
                    'severity' => $item['severity'],
                    'workflow_status' => $item['workflow_status'],
                    'priority' => $item['priority'],
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
            ->where('tenant_activity_logs.aggregate_type', 'finance_escalation_state')
            ->whereIn('tenant_activity_logs.event_type', [
                'finance.escalation.acknowledged',
                'finance.escalation.resolved',
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
            ->groupBy(fn (array $activity) => (string) ($activity['metadata']['escalation_code'] ?? ''))
            ->filter(fn (Collection $group, string $code) => $code !== '')
            ->flatMap(function (Collection $group, string $code) {
                $pairs = [];
                $lastAcknowledged = null;

                foreach ($group as $activity) {
                    if ($activity['event_type'] === 'finance.escalation.acknowledged') {
                        $lastAcknowledged = $activity;
                        continue;
                    }

                    if ($activity['event_type'] !== 'finance.escalation.resolved' || $lastAcknowledged === null) {
                        continue;
                    }

                    $pairs[] = [
                        'code' => $code,
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

    private function diffMinutes(string $from, string $to): float
    {
        return round(CarbonImmutable::parse($from)->diffInSeconds(CarbonImmutable::parse($to)) / 60, 2);
    }

    private function titleForCode(string $code): string
    {
        return match ($code) {
            'finance.stale_acknowledged' => 'Escalaciones financieras acknowledged envejecidas',
            'finance.broken_promise' => 'Promesas financieras incumplidas',
            'finance.severely_overdue' => 'Vencimientos financieros severos',
            'finance.stale_follow_up' => 'Seguimientos financieros desactualizados',
            'finance.missing_follow_up' => 'Prioridades financieras sin seguimiento',
            default => $code,
        };
    }
}
