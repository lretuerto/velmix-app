<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingEscalationHistoryService
{
    private const MAX_LIMIT = 20;
    private const MAX_ACTIVITY_LIMIT = 50;
    private const MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly BillingEscalationReportService $reportService,
        private readonly BillingEscalationStateService $stateService,
    ) {
    }

    public function index(
        int $tenantId,
        ?string $date = null,
        int $days = 7,
        int $historyDays = 30,
        int $limit = 10,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Escalation history window is invalid.');
        }

        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Escalation history limit is invalid.');
        }

        $baseDate = $this->resolveBaseDate($date);
        $activeReport = $this->reportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $days,
            count(BillingEscalationReportService::KNOWN_CODES),
        );
        $activeByCode = collect($activeReport['items'])
            ->keyBy('code')
            ->all();
        $statesByCode = $this->stateService->listByCode($tenantId);
        $activitiesByCode = $this->activitiesByCode($tenantId, $historyDays, $baseDate);

        $trackedCodes = collect(BillingEscalationReportService::KNOWN_CODES)
            ->filter(fn (string $code) => isset($activeByCode[$code]) || isset($statesByCode[$code]) || isset($activitiesByCode[$code]))
            ->values();

        $items = $trackedCodes
            ->map(function (string $code) use ($activeByCode, $statesByCode, $activitiesByCode) {
                return $this->buildIndexItem(
                    $code,
                    $activeByCode[$code] ?? null,
                    $statesByCode[$code] ?? null,
                    $activitiesByCode[$code] ?? [],
                );
            })
            ->sort(function (array $left, array $right): int {
                if ($left['is_currently_triggered'] !== $right['is_currently_triggered']) {
                    return $left['is_currently_triggered'] ? -1 : 1;
                }

                if ($left['last_activity_at'] !== $right['last_activity_at']) {
                    return strcmp((string) $right['last_activity_at'], (string) $left['last_activity_at']);
                }

                return strcmp($left['code'], $right['code']);
            })
            ->values()
            ->all();

        return [
            'tenant_id' => $tenantId,
            'active_window' => $activeReport['window'],
            'history_window' => $this->historyWindowData($historyDays, $baseDate),
            'summary' => [
                'tracked_count' => count($items),
                'active_count' => count(array_filter($items, fn (array $item) => $item['is_currently_triggered'])),
                'with_history_count' => count(array_filter($items, fn (array $item) => $item['timeline_count'] > 0)),
                'acknowledged_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'acknowledged')),
                'resolved_count' => count(array_filter($items, fn (array $item) => $item['workflow_status'] === 'resolved')),
            ],
            'items' => array_slice($items, 0, $limit),
        ];
    }

    public function detail(
        int $tenantId,
        string $code,
        ?string $date = null,
        int $days = 7,
        int $historyDays = 30,
        int $activityLimit = 20,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $code = trim($code);

        if ($code === '' || ! in_array($code, BillingEscalationReportService::KNOWN_CODES, true)) {
            throw new HttpException(404, 'Billing escalation code not found.');
        }

        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Escalation history window is invalid.');
        }

        if ($activityLimit <= 0 || $activityLimit > self::MAX_ACTIVITY_LIMIT) {
            throw new HttpException(422, 'Escalation activity limit is invalid.');
        }

        $baseDate = $this->resolveBaseDate($date);
        $activeReport = $this->reportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $days,
            count(BillingEscalationReportService::KNOWN_CODES),
        );
        $activeItem = collect($activeReport['items'])->firstWhere('code', $code);
        $state = $this->stateService->listByCode($tenantId)[$code] ?? null;
        $timeline = $this->activitiesByCode($tenantId, $historyDays, $baseDate)[$code] ?? [];

        if ($activeItem === null && $state === null && $timeline === []) {
            throw new HttpException(404, 'Billing escalation history not found.');
        }

        $latestActivity = $timeline[0] ?? null;

        return [
            'tenant_id' => $tenantId,
            'code' => $code,
            'title' => $activeItem['title'] ?? $this->titleForCode($code),
            'active_window' => $activeReport['window'],
            'history_window' => $this->historyWindowData($historyDays, $baseDate),
            'workflow_status' => $activeItem['workflow_status'] ?? $state['status'] ?? 'open',
            'is_currently_triggered' => $activeItem !== null,
            'active_item' => $activeItem,
            'state' => $state,
            'latest_note' => $latestActivity['metadata']['note'] ?? $state['resolution_note'] ?? $state['acknowledgement_note'] ?? null,
            'latest_activity' => $latestActivity,
            'timeline_summary' => [
                'total_count' => count($timeline),
                'acknowledged_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'billing.escalation.acknowledged')),
                'resolved_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'billing.escalation.resolved')),
            ],
            'timeline' => array_slice($timeline, 0, $activityLimit),
        ];
    }

    private function buildIndexItem(string $code, ?array $activeItem, ?array $state, array $timeline): array
    {
        $latestActivity = $timeline[0] ?? null;

        return [
            'code' => $code,
            'title' => $activeItem['title'] ?? $this->titleForCode($code),
            'severity' => $activeItem['severity'] ?? null,
            'priority' => $activeItem['priority'] ?? null,
            'workflow_status' => $activeItem['workflow_status'] ?? $state['status'] ?? 'open',
            'is_currently_triggered' => $activeItem !== null,
            'last_activity_at' => $latestActivity['occurred_at'] ?? $state['updated_at'] ?? null,
            'last_event_type' => $latestActivity['event_type'] ?? null,
            'last_actor' => $latestActivity['user'] ?? null,
            'last_note' => $latestActivity['metadata']['note'] ?? $state['resolution_note'] ?? $state['acknowledgement_note'] ?? null,
            'timeline_count' => count($timeline),
            'state' => $state,
            'active_item' => $activeItem,
        ];
    }

    private function activitiesByCode(int $tenantId, int $historyDays, CarbonImmutable $baseDate): array
    {
        return $this->fetchActivities($tenantId, $historyDays, $baseDate)
            ->groupBy(fn (array $activity) => (string) ($activity['metadata']['escalation_code'] ?? ''))
            ->filter(fn (Collection $group, string $code) => $code !== '')
            ->map(fn (Collection $group) => $group->values()->all())
            ->all();
    }

    private function fetchActivities(int $tenantId, int $historyDays, CarbonImmutable $baseDate): Collection
    {
        $window = $this->historyWindow($historyDays, $baseDate);

        return DB::table('tenant_activity_logs')
            ->leftJoin('users', 'users.id', '=', 'tenant_activity_logs.user_id')
            ->where('tenant_activity_logs.tenant_id', $tenantId)
            ->where('tenant_activity_logs.domain', 'billing')
            ->where('tenant_activity_logs.aggregate_type', 'billing_escalation_state')
            ->whereIn('tenant_activity_logs.event_type', [
                'billing.escalation.acknowledged',
                'billing.escalation.resolved',
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
                return [
                    'id' => (int) $activity->id,
                    'event_type' => (string) $activity->event_type,
                    'summary' => (string) $activity->summary,
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

    private function titleForCode(string $code): string
    {
        return match ($code) {
            'billing.health_stale' => 'Health check del provider desactualizado',
            'billing.failed_backlog' => 'Eventos fallidos pendientes de retry',
            'billing.pending_backlog' => 'Backlog pendiente de billing',
            'billing.failure_rate_high' => 'Tasa de fallos de billing elevada',
            'billing.acceptance_rate_low' => 'Acceptance rate por debajo del objetivo',
            'billing.replay_backlog' => 'Replays pendientes de billing',
            'billing.mixed_environments' => 'Actividad mixta entre sandbox y live',
            default => $code,
        };
    }

    private function resolveBaseDate(?string $date): CarbonImmutable
    {
        return $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
    }
}
