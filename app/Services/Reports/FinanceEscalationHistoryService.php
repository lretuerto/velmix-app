<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FinanceEscalationHistoryService
{
    private const MAX_LIMIT = 20;
    private const MAX_ACTIVITY_LIMIT = 50;
    private const MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly FinanceEscalationReportService $reportService,
        private readonly FinanceEscalationStateService $stateService,
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

        $activeReport = $this->reportService->summary(
            $tenantId,
            $date,
            $daysAhead,
            count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );
        $activeByCode = collect($activeReport['alerts'])
            ->keyBy('code')
            ->all();
        $statesByCode = $this->stateService->listByCode($tenantId);
        $activitiesByCode = $this->activitiesByCode(
            $tenantId,
            $historyDays,
            $this->resolveBaseDate($date),
        );

        $trackedCodes = collect(FinanceEscalationReportService::KNOWN_CODES)
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
            'history_window' => $this->historyWindowData($historyDays, $this->resolveBaseDate($date)),
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
        int $daysAhead = 7,
        int $historyDays = 30,
        int $activityLimit = 20,
        int $staleFollowUpDays = 3,
    ): array {
        $this->assertTenantId($tenantId);
        $this->assertHistoryDays($historyDays);
        $this->assertActivityLimit($activityLimit);
        $this->assertStaleFollowUpDays($staleFollowUpDays);

        $code = trim($code);

        if ($code === '' || ! in_array($code, FinanceEscalationReportService::KNOWN_CODES, true)) {
            throw new HttpException(404, 'Finance escalation history not found.');
        }

        $baseDate = $this->resolveBaseDate($date);
        $activeReport = $this->reportService->summary(
            $tenantId,
            $baseDate->toDateString(),
            $daysAhead,
            count(FinanceEscalationReportService::KNOWN_CODES),
            $staleFollowUpDays,
        );
        $activeItem = collect($activeReport['alerts'])->firstWhere('code', $code);
        $state = $this->stateService->listByCode($tenantId)[$code] ?? null;
        $timeline = $this->activitiesByCode($tenantId, $historyDays, $baseDate)[$code] ?? [];

        if ($activeItem === null && $state === null && $timeline === []) {
            throw new HttpException(404, 'Finance escalation history not found.');
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
                'acknowledged_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'finance.escalation.acknowledged')),
                'resolved_count' => count(array_filter($timeline, fn (array $activity) => $activity['event_type'] === 'finance.escalation.resolved')),
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
            ->where('tenant_activity_logs.domain', 'finance')
            ->where('tenant_activity_logs.aggregate_type', 'finance_escalation_state')
            ->whereIn('tenant_activity_logs.event_type', [
                'finance.escalation.acknowledged',
                'finance.escalation.resolved',
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

    private function assertHistoryDays(int $historyDays): void
    {
        if ($historyDays <= 0 || $historyDays > self::MAX_HISTORY_DAYS) {
            throw new HttpException(422, 'Finance escalation history window is invalid.');
        }
    }

    private function assertLimit(int $limit): void
    {
        if ($limit <= 0 || $limit > self::MAX_LIMIT) {
            throw new HttpException(422, 'Finance escalation history limit is invalid.');
        }
    }

    private function assertActivityLimit(int $activityLimit): void
    {
        if ($activityLimit <= 0 || $activityLimit > self::MAX_ACTIVITY_LIMIT) {
            throw new HttpException(422, 'Finance escalation activity limit is invalid.');
        }
    }

    private function assertStaleFollowUpDays(int $staleFollowUpDays): void
    {
        if ($staleFollowUpDays < 1 || $staleFollowUpDays > 30) {
            throw new HttpException(422, 'stale_follow_up_days is invalid.');
        }
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
