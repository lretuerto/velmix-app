<?php

namespace App\Services\Reports;

use App\Services\Billing\BillingProviderMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingOperationsReportService
{
    public function __construct(
        private readonly BillingProviderMetricsService $providerMetrics,
    ) {
    }

    public function summary(int $tenantId, ?string $date = null, int $days = 7, int $failureLimit = 5): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($days <= 0 || $days > 14) {
            throw new HttpException(422, 'Report window is invalid.');
        }

        $baseDate = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $metrics = $this->providerMetrics->summary($tenantId, $baseDate->toDateString(), $days, $failureLimit);
        $trend = $this->buildTrend($tenantId, $baseDate, $days);

        return [
            'tenant_id' => $tenantId,
            'window' => $metrics['window'],
            'executive_summary' => [
                'health_status' => $metrics['health']['current_status'],
                'health_is_stale' => $metrics['health']['is_stale'],
                'pending_backlog_count' => $metrics['queue']['pending_count'],
                'failed_backlog_count' => $metrics['queue']['failed_count'],
                'acceptance_rate' => $metrics['performance']['acceptance_rate'],
                'replay_backlog_count' => $metrics['replays']['pending_count'],
                'recent_failure_count' => count($metrics['recent_failures']),
            ],
            'environment_comparison' => $this->buildEnvironmentComparison($metrics['by_provider_environment']),
            'backlog_aging' => $this->buildBacklogAging($tenantId),
            'trend' => $trend,
            'worst_day' => $this->worstDay($trend),
            'recent_failures' => $metrics['recent_failures'],
            'alerts' => $metrics['alerts'],
        ];
    }

    private function buildTrend(int $tenantId, CarbonImmutable $baseDate, int $days): array
    {
        $trend = [];
        $windowStart = $baseDate->subDays($days - 1);

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $windowStart->addDays($offset);
            $metrics = $this->providerMetrics->summary($tenantId, $day->toDateString(), 1, 1);

            $trend[] = [
                'date' => $day->toDateString(),
                'event_count' => $metrics['performance']['event_count'],
                'accepted_event_count' => $metrics['performance']['accepted_event_count'],
                'rejected_event_count' => $metrics['performance']['rejected_event_count'],
                'failed_event_count' => $metrics['performance']['failed_event_count'],
                'pending_event_count' => $metrics['performance']['pending_event_count'],
                'acceptance_rate' => $metrics['performance']['acceptance_rate'],
                'replay_created_count' => $metrics['replays']['created_count'],
                'replay_pending_count' => $metrics['replays']['pending_count'],
            ];
        }

        return $trend;
    }

    private function buildEnvironmentComparison(array $breakdown): array
    {
        return collect($breakdown)
            ->map(function (array $environment) {
                $attemptCount = (int) ($environment['attempt_count'] ?? 0);

                return [
                    'provider_code' => (string) $environment['provider_code'],
                    'provider_environment' => (string) $environment['provider_environment'],
                    'attempt_count' => $attemptCount,
                    'accepted_count' => (int) ($environment['accepted_count'] ?? 0),
                    'rejected_count' => (int) ($environment['rejected_count'] ?? 0),
                    'failed_count' => (int) ($environment['failed_count'] ?? 0),
                    'acceptance_rate' => $attemptCount > 0
                        ? round(((int) ($environment['accepted_count'] ?? 0) / $attemptCount) * 100, 2)
                        : 0.0,
                ];
            })
            ->sortBy([
                ['provider_environment', 'asc'],
                ['provider_code', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function buildBacklogAging(int $tenantId): array
    {
        $pendingEvents = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get(['id', 'replayed_from_event_id', 'created_at']);

        $buckets = [
            '0_15_count' => 0,
            '16_60_count' => 0,
            '61_240_count' => 0,
            '241_plus_count' => 0,
        ];
        $oldestPendingAgeMinutes = null;

        foreach ($pendingEvents as $event) {
            $ageMinutes = (int) floor(CarbonImmutable::parse((string) $event->created_at)->diffInSeconds(CarbonImmutable::now()) / 60);
            $oldestPendingAgeMinutes ??= $ageMinutes;

            if ($ageMinutes <= 15) {
                $buckets['0_15_count']++;
            } elseif ($ageMinutes <= 60) {
                $buckets['16_60_count']++;
            } elseif ($ageMinutes <= 240) {
                $buckets['61_240_count']++;
            } else {
                $buckets['241_plus_count']++;
            }
        }

        return array_merge($buckets, [
            'total_pending_count' => $pendingEvents->count(),
            'replay_pending_count' => $pendingEvents->filter(fn (object $event) => $event->replayed_from_event_id !== null)->count(),
            'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
        ]);
    }

    private function worstDay(array $trend): ?array
    {
        if ($trend === []) {
            return null;
        }

        return collect($trend)
            ->sortByDesc(fn (array $day) => [
                $day['failed_event_count'],
                $day['pending_event_count'],
                -$day['acceptance_rate'],
                $day['replay_pending_count'],
            ])
            ->first();
    }
}
