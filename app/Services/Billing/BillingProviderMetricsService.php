<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingProviderMetricsService
{
    private const HEALTH_STALE_HOURS = 24;

    public function summary(int $tenantId, ?string $date = null, int $days = 7, int $recentFailuresLimit = 5): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($days <= 0 || $days > 30) {
            throw new HttpException(422, 'Metrics window is invalid.');
        }

        if ($recentFailuresLimit <= 0 || $recentFailuresLimit > 20) {
            throw new HttpException(422, 'Recent failure limit is invalid.');
        }

        $windowEnd = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->endOfDay()
            : CarbonImmutable::now()->endOfDay();
        $windowStart = $windowEnd->startOfDay()->subDays($days - 1);
        $windowEndExclusive = $windowEnd->addSecond();

        $profile = app(BillingProviderProfileService::class)->current($tenantId);
        $queueSummary = app(OutboxDispatchService::class)->queueSummary($tenantId);

        $events = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEndExclusive)
            ->orderBy('id')
            ->get([
                'id',
                'aggregate_type',
                'aggregate_id',
                'event_type',
                'status',
                'replayed_from_event_id',
                'created_at',
                'updated_at',
            ]);

        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attempts = $eventIds === []
            ? collect()
            : DB::table('outbox_attempts')
                ->whereIn('outbox_event_id', $eventIds)
                ->orderBy('id')
                ->get([
                    'id',
                    'outbox_event_id',
                    'status',
                    'provider_code',
                    'provider_environment',
                    'provider_reference',
                    'sunat_ticket',
                    'error_message',
                    'created_at',
                ]);

        $eventMap = $events->keyBy(fn (object $event) => (int) $event->id);
        $attemptsByEvent = $attempts->groupBy('outbox_event_id');
        $firstAttempts = $attemptsByEvent->map(fn (Collection $items) => $items->first());
        $latestAttempts = $attemptsByEvent->map(fn (Collection $items) => $items->last());

        $performance = $this->buildPerformanceSummary($events, $eventMap, $attempts, $firstAttempts, $latestAttempts);
        $replays = $this->buildReplaySummary($events, $latestAttempts);
        $health = $this->buildHealthSummary($profile);
        $queue = $this->buildQueueSummary($queueSummary);

        return [
            'tenant_id' => $tenantId,
            'window' => [
                'start_date' => $windowStart->toDateString(),
                'end_date' => $windowEnd->toDateString(),
                'days' => $days,
            ],
            'provider_profile' => app(BillingProviderProfileService::class)->publicSerializeArray($profile),
            'health' => $health,
            'queue' => $queue,
            'performance' => $performance,
            'replays' => $replays,
            'by_provider_environment' => $this->buildEnvironmentBreakdown($attempts, $profile),
            'recent_failures' => $this->buildRecentFailures($attempts, $eventMap, $profile, $recentFailuresLimit),
            'alerts' => $this->buildAlerts($health, $queue, $performance, $replays),
        ];
    }

    private function buildPerformanceSummary(
        Collection $events,
        Collection $eventMap,
        Collection $attempts,
        Collection $firstAttempts,
        Collection $latestAttempts,
    ): array {
        $acceptedEvents = 0;
        $rejectedEvents = 0;
        $failedEvents = 0;
        $pendingEvents = 0;

        foreach ($events as $event) {
            $latestAttempt = $latestAttempts->get((int) $event->id);

            if ($latestAttempt !== null) {
                match ((string) $latestAttempt->status) {
                    'accepted' => $acceptedEvents++,
                    'rejected' => $rejectedEvents++,
                    'failed' => $failedEvents++,
                    default => $pendingEvents++,
                };

                continue;
            }

            if ((string) $event->status === 'pending') {
                $pendingEvents++;
            } elseif ((string) $event->status === 'failed') {
                $failedEvents++;
            } else {
                $pendingEvents++;
            }
        }

        $firstAttemptDelays = $firstAttempts
            ->map(fn (object $attempt) => $this->diffMinutes(
                (string) $eventMap->get((int) $attempt->outbox_event_id)->created_at,
                (string) $attempt->created_at,
            ))
            ->values();

        $resolutionDelays = $latestAttempts
            ->filter(fn (object $attempt) => in_array((string) $attempt->status, ['accepted', 'rejected', 'failed'], true))
            ->map(fn (object $attempt) => $this->diffMinutes(
                (string) $eventMap->get((int) $attempt->outbox_event_id)->created_at,
                (string) $attempt->created_at,
            ))
            ->values();

        $eventCount = $events->count();

        return [
            'event_count' => $eventCount,
            'attempt_count' => $attempts->count(),
            'accepted_event_count' => $acceptedEvents,
            'rejected_event_count' => $rejectedEvents,
            'failed_event_count' => $failedEvents,
            'pending_event_count' => $pendingEvents,
            'accepted_attempt_count' => $attempts->where('status', 'accepted')->count(),
            'rejected_attempt_count' => $attempts->where('status', 'rejected')->count(),
            'failed_attempt_count' => $attempts->where('status', 'failed')->count(),
            'acceptance_rate' => $this->percentage($acceptedEvents, $eventCount),
            'rejection_rate' => $this->percentage($rejectedEvents, $eventCount),
            'failure_rate' => $this->percentage($failedEvents, $eventCount),
            'avg_first_attempt_delay_minutes' => $firstAttemptDelays->isNotEmpty()
                ? round((float) $firstAttemptDelays->avg(), 2)
                : null,
            'avg_resolution_delay_minutes' => $resolutionDelays->isNotEmpty()
                ? round((float) $resolutionDelays->avg(), 2)
                : null,
        ];
    }

    private function buildReplaySummary(Collection $events, Collection $latestAttempts): array
    {
        $replayEvents = $events->filter(fn (object $event) => $event->replayed_from_event_id !== null)->values();
        $accepted = 0;
        $rejected = 0;
        $failed = 0;
        $pending = 0;

        foreach ($replayEvents as $event) {
            $latestAttempt = $latestAttempts->get((int) $event->id);

            if ($latestAttempt !== null) {
                match ((string) $latestAttempt->status) {
                    'accepted' => $accepted++,
                    'rejected' => $rejected++,
                    'failed' => $failed++,
                    default => $pending++,
                };

                continue;
            }

            if ((string) $event->status === 'pending') {
                $pending++;
            } elseif ((string) $event->status === 'failed') {
                $failed++;
            } else {
                $pending++;
            }
        }

        return [
            'created_count' => $replayEvents->count(),
            'accepted_count' => $accepted,
            'rejected_count' => $rejected,
            'failed_count' => $failed,
            'pending_count' => $pending,
        ];
    }

    private function buildHealthSummary(array $profile): array
    {
        $checkedAt = $profile['health_checked_at'] !== null
            ? CarbonImmutable::parse((string) $profile['health_checked_at'])
            : null;
        $isStale = $checkedAt === null || $checkedAt->lt(CarbonImmutable::now()->subHours(self::HEALTH_STALE_HOURS));

        return [
            'current_status' => (string) $profile['health_status'],
            'checked_at' => $profile['health_checked_at'],
            'message' => $profile['health_message'],
            'stale_after_hours' => self::HEALTH_STALE_HOURS,
            'is_stale' => $isStale,
        ];
    }

    private function buildQueueSummary(array $queueSummary): array
    {
        $oldestPendingAgeMinutes = null;

        if (($queueSummary['oldest_pending']['created_at'] ?? null) !== null) {
            $oldestPendingAgeMinutes = $this->diffMinutes(
                (string) $queueSummary['oldest_pending']['created_at'],
                CarbonImmutable::now()->toDateTimeString(),
            );
        }

        return [
            'total_count' => (int) ($queueSummary['total_count'] ?? 0),
            'pending_count' => (int) ($queueSummary['pending_count'] ?? 0),
            'failed_count' => (int) ($queueSummary['failed_count'] ?? 0),
            'processed_count' => (int) ($queueSummary['processed_count'] ?? 0),
            'oldest_pending' => $queueSummary['oldest_pending'] ?? null,
            'oldest_pending_age_minutes' => $oldestPendingAgeMinutes,
            'latest_attempt' => $queueSummary['latest_attempt'] ?? null,
        ];
    }

    private function buildEnvironmentBreakdown(Collection $attempts, array $profile): array
    {
        return $attempts
            ->groupBy(function (object $attempt) use ($profile) {
                $providerCode = $attempt->provider_code !== null ? (string) $attempt->provider_code : (string) $profile['provider_code'];
                $environment = $attempt->provider_environment !== null ? (string) $attempt->provider_environment : (string) $profile['environment'];

                return $providerCode.'|'.$environment;
            })
            ->map(function (Collection $items, string $key) {
                [$providerCode, $environment] = explode('|', $key, 2);

                return [
                    'provider_code' => $providerCode,
                    'provider_environment' => $environment,
                    'attempt_count' => $items->count(),
                    'accepted_count' => $items->where('status', 'accepted')->count(),
                    'rejected_count' => $items->where('status', 'rejected')->count(),
                    'failed_count' => $items->where('status', 'failed')->count(),
                ];
            })
            ->sortBy([
                ['provider_code', 'asc'],
                ['provider_environment', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function buildRecentFailures(Collection $attempts, Collection $eventMap, array $profile, int $limit): array
    {
        return $attempts
            ->filter(fn (object $attempt) => in_array((string) $attempt->status, ['failed', 'rejected'], true))
            ->sortByDesc('id')
            ->take($limit)
            ->map(function (object $attempt) use ($eventMap, $profile) {
                $event = $eventMap->get((int) $attempt->outbox_event_id);

                return [
                    'attempt_id' => (int) $attempt->id,
                    'event_id' => (int) $attempt->outbox_event_id,
                    'aggregate_type' => (string) $event->aggregate_type,
                    'aggregate_id' => (int) $event->aggregate_id,
                    'event_type' => (string) $event->event_type,
                    'status' => (string) $attempt->status,
                    'provider_code' => $attempt->provider_code !== null ? (string) $attempt->provider_code : (string) $profile['provider_code'],
                    'provider_environment' => $attempt->provider_environment !== null ? (string) $attempt->provider_environment : (string) $profile['environment'],
                    'provider_reference' => $attempt->provider_reference,
                    'error_message' => $attempt->error_message,
                    'created_at' => (string) $attempt->created_at,
                ];
            })
            ->values()
            ->all();
    }

    private function buildAlerts(array $health, array $queue, array $performance, array $replays): array
    {
        $alerts = [];

        if ($health['is_stale']) {
            $alerts[] = [
                'code' => 'health_stale',
                'severity' => 'warning',
                'message' => 'Billing provider health snapshot is stale or missing.',
            ];
        }

        if (($queue['failed_count'] ?? 0) > 0) {
            $alerts[] = [
                'code' => 'failed_backlog',
                'severity' => 'critical',
                'message' => 'Billing outbox has failed events pending retry.',
            ];
        }

        if (($queue['pending_count'] ?? 0) > 0 && ($queue['oldest_pending_age_minutes'] ?? 0) >= 30) {
            $alerts[] = [
                'code' => 'pending_backlog',
                'severity' => 'warning',
                'message' => 'Billing outbox has pending backlog older than 30 minutes.',
            ];
        }

        if (($performance['event_count'] ?? 0) > 0 && ($performance['failure_rate'] ?? 0.0) >= 20.0) {
            $alerts[] = [
                'code' => 'failure_rate_high',
                'severity' => 'warning',
                'message' => 'Billing dispatch failure rate is above the expected threshold.',
            ];
        }

        if (($replays['pending_count'] ?? 0) > 0) {
            $alerts[] = [
                'code' => 'replay_backlog',
                'severity' => 'info',
                'message' => 'There are replayed billing events still awaiting dispatch.',
            ];
        }

        return $alerts;
    }

    private function percentage(int $numerator, int $denominator): float
    {
        if ($denominator === 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function diffMinutes(string $from, string $to): float
    {
        return round(CarbonImmutable::parse($from)->diffInSeconds(CarbonImmutable::parse($to)) / 60, 2);
    }
}
