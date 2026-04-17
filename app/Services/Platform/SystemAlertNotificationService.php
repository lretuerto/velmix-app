<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemAlertNotificationService
{
    private const SEVERITY_RANK = [
        'info' => 1,
        'warning' => 2,
        'critical' => 3,
    ];

    public function __construct(
        private readonly SystemAlertService $alerts,
    ) {}

    public function dispatch(?string $date = null, bool $force = false): array
    {
        $summary = $this->alerts->summary($date);
        $config = config('velmix.alerts.notifications', []);
        $channels = array_values(array_filter(array_map(
            static fn ($channel) => is_string($channel) ? trim($channel) : null,
            (array) ($config['channels'] ?? [])
        )));
        $minimumSeverity = (string) ($config['minimum_severity'] ?? 'warning');
        $cooldownMinutes = max(1, (int) ($config['cooldown_minutes'] ?? 30));

        $candidateAlerts = array_values(array_filter(
            $summary['items'] ?? [],
            fn (array $alert) => $this->shouldDispatchSeverity((string) ($alert['severity'] ?? 'info'), $minimumSeverity)
        ));

        if ($candidateAlerts === [] || $channels === []) {
            return [
                'status' => 'ok',
                'checked_at' => now()->toIso8601String(),
                'summary' => $summary['summary'] ?? [],
                'notification' => [
                    'channels' => $channels,
                    'candidate_alert_count' => count($candidateAlerts),
                    'dispatched_count' => 0,
                    'suppressed_count' => 0,
                    'failed_count' => 0,
                    'items' => [],
                ],
            ];
        }

        $results = [];

        foreach ($candidateAlerts as $alert) {
            foreach ($channels as $channel) {
                $results[] = $this->dispatchAlert($summary, $alert, $channel, $cooldownMinutes, $force, $config);
            }
        }

        $failedCount = count(array_filter($results, fn (array $result) => $result['status'] === 'failed'));
        $dispatchedCount = count(array_filter($results, fn (array $result) => $result['status'] === 'dispatched'));
        $suppressedCount = count(array_filter($results, fn (array $result) => $result['status'] === 'suppressed'));

        return [
            'status' => $failedCount > 0 ? 'partial_failure' : 'ok',
            'checked_at' => now()->toIso8601String(),
            'summary' => $summary['summary'] ?? [],
            'notification' => [
                'channels' => $channels,
                'candidate_alert_count' => count($candidateAlerts),
                'dispatched_count' => $dispatchedCount,
                'suppressed_count' => $suppressedCount,
                'failed_count' => $failedCount,
                'items' => $results,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $alert
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function dispatchAlert(array $summary, array $alert, string $channel, int $cooldownMinutes, bool $force, array $config): array
    {
        $fingerprint = $this->fingerprint($alert, $channel);
        $cooldownKey = sprintf('velmix:system-alert-notify:%s', $fingerprint);

        if (! $force && $this->isSuppressed($cooldownKey)) {
            return [
                'status' => 'suppressed',
                'channel' => $channel,
                'severity' => $alert['severity'] ?? null,
                'code' => $alert['code'] ?? null,
                'tenant_id' => $alert['tenant_id'] ?? null,
                'reason' => 'cooldown_active',
            ];
        }

        try {
            $payload = $this->buildPayload($summary, $alert, $channel, $fingerprint);

            match ($channel) {
                'log' => $this->dispatchToLog($payload, $config),
                'webhook' => $this->dispatchToWebhook($payload, $config),
                default => throw new \RuntimeException(sprintf('Unsupported alert notification channel [%s].', $channel)),
            };

            $this->rememberSuppression($cooldownKey, $cooldownMinutes);

            return [
                'status' => 'dispatched',
                'channel' => $channel,
                'severity' => $alert['severity'] ?? null,
                'code' => $alert['code'] ?? null,
                'tenant_id' => $alert['tenant_id'] ?? null,
                'fingerprint' => $fingerprint,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'channel' => $channel,
                'severity' => $alert['severity'] ?? null,
                'code' => $alert['code'] ?? null,
                'tenant_id' => $alert['tenant_id'] ?? null,
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function buildPayload(array $summary, array $alert, string $channel, string $fingerprint): array
    {
        return [
            'sent_at' => now()->toIso8601String(),
            'application' => config('app.name'),
            'environment' => config('app.env'),
            'channel' => $channel,
            'fingerprint' => $fingerprint,
            'summary' => [
                'status' => $summary['status'] ?? 'unknown',
                'date' => $summary['date'] ?? null,
                'counts' => $summary['summary'] ?? [],
            ],
            'alert' => [
                'severity' => $alert['severity'] ?? null,
                'scope' => $alert['scope'] ?? null,
                'code' => $alert['code'] ?? null,
                'tenant_id' => $alert['tenant_id'] ?? null,
                'message' => $alert['message'] ?? null,
                'action' => $alert['action'] ?? null,
                'path' => $alert['path'] ?? null,
                'metric_snapshot' => $alert['metric_snapshot'] ?? [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function dispatchToLog(array $payload, array $config): void
    {
        $logChannel = (string) ($config['log_channel'] ?? 'daily_json');

        Log::channel($logChannel)->warning('system_alert_notification', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function dispatchToWebhook(array $payload, array $config): void
    {
        $url = trim((string) ($config['webhook_url'] ?? ''));

        if ($url === '') {
            throw new \RuntimeException('VELMIX_ALERT_WEBHOOK_URL is required for webhook notifications.');
        }

        $timeoutSeconds = max(1, (int) ($config['webhook_timeout_seconds'] ?? 5));
        $response = Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Webhook notification failed with status %d.', $response->status()));
        }
    }

    private function shouldDispatchSeverity(string $severity, string $minimumSeverity): bool
    {
        $current = self::SEVERITY_RANK[$severity] ?? 0;
        $minimum = self::SEVERITY_RANK[$minimumSeverity] ?? self::SEVERITY_RANK['warning'];

        return $current >= $minimum;
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function fingerprint(array $alert, string $channel): string
    {
        return sha1(json_encode([
            'env' => config('app.env'),
            'channel' => $channel,
            'severity' => $alert['severity'] ?? null,
            'scope' => $alert['scope'] ?? null,
            'code' => $alert['code'] ?? null,
            'tenant_id' => $alert['tenant_id'] ?? null,
            'path' => $alert['path'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function isSuppressed(string $cacheKey): bool
    {
        try {
            return Cache::has($cacheKey);
        } catch (Throwable) {
            return false;
        }
    }

    private function rememberSuppression(string $cacheKey, int $cooldownMinutes): void
    {
        try {
            Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));
        } catch (Throwable) {
            // Alert delivery should degrade safely even if cache is unavailable.
        }
    }
}
