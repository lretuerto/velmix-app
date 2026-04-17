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
        $config = $this->notificationConfig();
        $channels = $this->configuredChannels($config);
        $minimumSeverity = (string) ($config['minimum_severity'] ?? 'warning');
        $cooldownMinutes = max(1, (int) ($config['cooldown_minutes'] ?? 30));

        $candidateAlerts = $this->candidateAlerts($summary, $minimumSeverity);

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

    public function describe(?string $date = null): array
    {
        $summary = $this->alerts->summary($date);
        $config = $this->notificationConfig();
        $channels = $this->configuredChannels($config);
        $minimumSeverity = (string) ($config['minimum_severity'] ?? 'warning');
        $candidateAlerts = $this->candidateAlerts($summary, $minimumSeverity);

        return [
            'checked_at' => now()->toIso8601String(),
            'minimum_severity' => $minimumSeverity,
            'candidate_alert_count' => count($candidateAlerts),
            'channels' => array_map(
                fn (string $channel) => $this->describeChannel($channel, $config),
                $channels
            ),
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
                'slack' => $this->dispatchToSlack($payload, $config),
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function dispatchToSlack(array $payload, array $config): void
    {
        $url = trim((string) ($config['slack_webhook_url'] ?? ''));

        if ($url === '') {
            throw new \RuntimeException('VELMIX_ALERT_SLACK_WEBHOOK_URL is required for slack notifications.');
        }

        $timeoutSeconds = max(1, (int) ($config['webhook_timeout_seconds'] ?? 5));
        $response = Http::timeout($timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post($url, $this->buildSlackPayload($payload, $config));

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Slack notification failed with status %d.', $response->status()));
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

    /**
     * @return array<string, mixed>
     */
    private function notificationConfig(): array
    {
        return (array) config('velmix.alerts.notifications', []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function configuredChannels(array $config): array
    {
        return array_values(array_filter(array_map(
            static fn ($channel) => is_string($channel) ? trim($channel) : null,
            (array) ($config['channels'] ?? [])
        )));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array<string, mixed>>
     */
    private function candidateAlerts(array $summary, string $minimumSeverity): array
    {
        return array_values(array_filter(
            $summary['items'] ?? [],
            fn (array $alert) => $this->shouldDispatchSeverity((string) ($alert['severity'] ?? 'info'), $minimumSeverity)
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function describeChannel(string $channel, array $config): array
    {
        return match ($channel) {
            'log' => $this->describeLogChannel($config),
            'webhook' => $this->describeWebhookChannel($config),
            'slack' => $this->describeSlackChannel($config),
            default => [
                'channel' => $channel,
                'status' => 'critical',
                'configured' => false,
                'destination' => null,
                'message' => sprintf('Unsupported alert notification channel [%s].', $channel),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function describeLogChannel(array $config): array
    {
        $logChannel = (string) ($config['log_channel'] ?? 'daily_json');
        $knownChannels = (array) config('logging.channels', []);
        $configured = is_array($knownChannels[$logChannel] ?? null);

        return [
            'channel' => 'log',
            'status' => $configured ? 'ready' : 'critical',
            'configured' => $configured,
            'destination' => $logChannel,
            'message' => $configured
                ? 'Structured log alert delivery is ready.'
                : 'Configured alert log channel is missing.',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function describeWebhookChannel(array $config): array
    {
        $url = trim((string) ($config['webhook_url'] ?? ''));
        $configured = $url !== '';

        return [
            'channel' => 'webhook',
            'status' => $configured ? 'ready' : 'warning',
            'configured' => $configured,
            'destination' => $this->redactUrlHost($url),
            'message' => $configured
                ? 'Generic webhook alert delivery is configured.'
                : 'Generic webhook alert delivery is disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function describeSlackChannel(array $config): array
    {
        $url = trim((string) ($config['slack_webhook_url'] ?? ''));
        $configured = $url !== '';

        return [
            'channel' => 'slack',
            'status' => $configured ? 'ready' : 'warning',
            'configured' => $configured,
            'destination' => $this->redactUrlHost($url),
            'message' => $configured
                ? 'Slack alert delivery is configured.'
                : 'Slack alert delivery is disabled.',
            'slack_channel' => $config['slack_channel'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function buildSlackPayload(array $payload, array $config): array
    {
        $alert = (array) ($payload['alert'] ?? []);
        $summary = (array) ($payload['summary'] ?? []);
        $text = sprintf(
            '[%s] %s | code=%s | tenant=%s | status=%s',
            strtoupper((string) ($alert['severity'] ?? 'info')),
            (string) ($alert['message'] ?? 'Operational alert'),
            (string) ($alert['code'] ?? 'n/a'),
            $alert['tenant_id'] ?? 'platform',
            (string) ($summary['status'] ?? 'unknown'),
        );

        $message = [
            'text' => $text,
            'username' => (string) ($config['slack_username'] ?? 'VELMiX Alerts'),
            'icon_emoji' => (string) ($config['slack_icon_emoji'] ?? ':rotating_light:'),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            '*%s*\\n%s\\nTenant: `%s`\\nCode: `%s`',
                            strtoupper((string) ($alert['severity'] ?? 'info')),
                            (string) ($alert['message'] ?? 'Operational alert'),
                            $alert['tenant_id'] ?? 'platform',
                            (string) ($alert['code'] ?? 'n/a'),
                        ),
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => sprintf(
                                'Action: %s | Path: %s',
                                (string) ($alert['action'] ?? 'Review alert'),
                                (string) ($alert['path'] ?? 'n/a'),
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $channel = trim((string) ($config['slack_channel'] ?? ''));

        if ($channel !== '') {
            $message['channel'] = $channel;
        }

        return $message;
    }

    private function redactUrlHost(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'configured';
    }
}
