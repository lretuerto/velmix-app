<?php

namespace App\Services\Platform;

class SystemObservabilityReportService
{
    public function __construct(
        private readonly SystemPreflightService $preflight,
        private readonly SystemAlertService $alerts,
    ) {}

    public function summary(?string $date = null): array
    {
        $preflight = $this->preflight->summary();
        $alerts = $this->alerts->summary($date);
        $logging = $this->loggingSnapshot();
        $notificationConfig = (array) config('velmix.alerts.notifications', []);

        return [
            'status' => $this->resolveStatus($preflight, $alerts),
            'checked_at' => now()->toIso8601String(),
            'request_correlation' => [
                'request_id_header' => 'X-Request-Id',
                'response_header' => 'X-Request-Id',
            ],
            'preflight' => [
                'status' => $preflight['status'] ?? 'unknown',
                'item_count' => count($preflight['items'] ?? []),
            ],
            'alerts' => [
                'status' => $alerts['status'] ?? 'unknown',
                'summary' => $alerts['summary'] ?? [],
            ],
            'logging' => $logging,
            'queue' => [
                'connection' => config('queue.default'),
                'driver' => config(sprintf('queue.connections.%s.driver', config('queue.default'))),
                'batching_table' => config('queue.batching.table'),
                'failed_driver' => config('queue.failed.driver'),
                'failed_table' => config('queue.failed.table'),
            ],
            'scheduler' => [
                'timezone' => config('velmix.scheduler.timezone'),
                'on_one_server' => config('velmix.scheduler.on_one_server'),
                'dispatch_every_minutes' => config('velmix.scheduler.dispatch_every_minutes'),
                'reconcile_every_minutes' => config('velmix.scheduler.reconcile_every_minutes'),
                'alerts_every_minutes' => config('velmix.scheduler.alerts_every_minutes'),
                'alert_dispatch_every_minutes' => config('velmix.scheduler.alert_dispatch_every_minutes'),
                'prune_at' => config('velmix.scheduler.prune_at'),
            ],
            'notifications' => [
                'channels' => array_values((array) ($notificationConfig['channels'] ?? [])),
                'minimum_severity' => $notificationConfig['minimum_severity'] ?? 'warning',
                'cooldown_minutes' => $notificationConfig['cooldown_minutes'] ?? 30,
                'webhook_enabled' => trim((string) ($notificationConfig['webhook_url'] ?? '')) !== '',
                'log_channel' => $notificationConfig['log_channel'] ?? 'daily_json',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loggingSnapshot(): array
    {
        $defaultChannel = (string) config('logging.default', '');
        $channels = (array) config('logging.channels', []);
        $effectiveChannels = $this->resolveEffectiveLoggingChannels($defaultChannel, $channels);

        return [
            'default_channel' => $defaultChannel !== '' ? $defaultChannel : null,
            'effective_channels' => $effectiveChannels,
            'structured_logging_enabled' => collect($effectiveChannels)
                ->contains(fn (string $channel) => in_array($channel, ['stderr_json', 'daily_json'], true)),
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     */
    private function resolveStatus(array $preflight, array $alerts): string
    {
        foreach ([$preflight['status'] ?? 'ok', $alerts['status'] ?? 'ok'] as $status) {
            if ($status === 'critical') {
                return 'critical';
            }

            if ($status === 'warning') {
                $resolved = 'warning';
            }
        }

        return $resolved ?? 'ok';
    }

    /**
     * @param  array<string, mixed>  $channels
     * @return array<int, string>
     */
    private function resolveEffectiveLoggingChannels(string $defaultChannel, array $channels): array
    {
        $defaultDefinition = $channels[$defaultChannel] ?? null;

        if (! is_array($defaultDefinition)) {
            return [];
        }

        if (($defaultDefinition['driver'] ?? null) !== 'stack') {
            return [$defaultChannel];
        }

        $stackChannels = $defaultDefinition['channels'] ?? [];

        if (is_string($stackChannels)) {
            $stackChannels = explode(',', $stackChannels);
        }

        if (! is_array($stackChannels)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($channel) => is_string($channel) ? trim($channel) : null,
            $stackChannels
        )));
    }
}
