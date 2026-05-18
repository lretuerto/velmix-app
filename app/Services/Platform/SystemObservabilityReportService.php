<?php

namespace App\Services\Platform;

class SystemObservabilityReportService
{
    public function __construct(
        private readonly SystemPreflightService $preflight,
        private readonly SystemAlertService $alerts,
        private readonly SystemAlertNotificationService $notifications,
        private readonly BackupRecoveryService $backupRecovery,
        private readonly StagingCertificationService $stagingCertification,
        private readonly ReleasePromotionService $releasePromotion,
        private readonly ReleaseCutoverService $releaseCutover,
        private readonly OperationalCertificationService $operationalCertification,
    ) {}

    public function summary(?string $date = null): array
    {
        $preflight = $this->preflight->summary();
        $alerts = $this->alerts->summary($date);
        $delivery = $this->notifications->describe($date);
        $recovery = $this->backupRecovery->observabilitySummary();
        $certification = $this->stagingCertification->summary([
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
        ]);
        $promotion = $this->releasePromotion->summary([
            'date' => $date,
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
            'certification' => $certification,
        ]);
        $cutover = $this->releaseCutover->summary([
            'date' => $date,
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
            'promotion' => $promotion,
        ]);
        $operationalCertification = $this->operationalCertification->summary([
            'date' => $date,
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
            'promotion' => $promotion,
            'cutover' => $cutover,
        ]);
        $logging = $this->loggingSnapshot();
        $notificationConfig = (array) config('velmix.alerts.notifications', []);

        return [
            'status' => $this->resolveStatus($preflight, $alerts, $delivery, $recovery, $certification, $promotion, $cutover, $operationalCertification),
            'checked_at' => now()->toIso8601String(),
            'request_correlation' => [
                'request_id_header' => 'X-Request-Id',
                'response_header' => 'X-Request-Id',
            ],
            'preflight' => [
                'status' => $preflight['status'] ?? 'unknown',
                'item_count' => count($preflight['items'] ?? []),
            ],
            'cash_ledger' => $this->cashLedgerSnapshot($preflight),
            'frontend_uat_release_gate' => $this->frontendUatReleaseGateSnapshot($preflight),
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
                'slack_enabled' => trim((string) ($notificationConfig['slack_webhook_url'] ?? '')) !== '',
                'log_channel' => $notificationConfig['log_channel'] ?? 'daily_json',
            ],
            'delivery' => $delivery,
            'recovery' => $recovery,
            'certification' => [
                'staging' => $certification,
            ],
            'promotion' => $promotion,
            'cutover' => $cutover,
            'operational_certification' => $operationalCertification,
            'recommendations' => $this->recommendations($preflight, $alerts, $logging, $delivery, $recovery, $certification, $promotion, $cutover, $operationalCertification),
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function cashLedgerSnapshot(array $preflight): array
    {
        $cashLedger = (array) ($preflight['checks']['cash_ledger'] ?? []);
        $audit = (array) ($cashLedger['audit'] ?? []);

        return [
            'status' => $cashLedger['status'] ?? 'unknown',
            'enabled' => (bool) ($cashLedger['enabled'] ?? false),
            'required' => (bool) ($cashLedger['required'] ?? false),
            'tenant_id' => $cashLedger['tenant_id'] ?? null,
            'session_id' => $cashLedger['session_id'] ?? null,
            'issue_count' => (int) ($audit['issue_count'] ?? 0),
            'truncated' => (bool) ($audit['truncated'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @return array<string, mixed>
     */
    private function frontendUatReleaseGateSnapshot(array $preflight): array
    {
        $gate = (array) ($preflight['checks']['frontend_uat_release_gate'] ?? []);
        $readiness = (array) ($gate['readiness'] ?? []);

        return [
            'status' => $gate['status'] ?? 'unknown',
            'enabled' => (bool) ($gate['enabled'] ?? false),
            'required' => (bool) ($gate['required'] ?? false),
            'freshness_hours' => (int) ($gate['freshness_hours'] ?? 24),
            'readiness_status' => $readiness['status'] ?? null,
            'item_count' => (int) ($readiness['item_count'] ?? 0),
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
    private function resolveStatus(array $preflight, array $alerts, array $delivery, array $recovery, array $certification, array $promotion, array $cutover, array $operationalCertification): string
    {
        $resolved = null;
        $statuses = [
            $preflight['status'] ?? 'ok',
            $alerts['status'] ?? 'ok',
            $recovery['backup']['status'] ?? 'ok',
            $recovery['restore_drill']['status'] ?? 'ok',
            $certification['status'] ?? 'ok',
            $promotion['status'] ?? 'ok',
            $cutover['status'] ?? 'ok',
            $operationalCertification['status'] ?? 'ok',
        ];

        foreach ((array) ($delivery['channels'] ?? []) as $channel) {
            $statuses[] = (string) ($channel['status'] ?? 'ok');
        }

        foreach ($statuses as $status) {
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
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     * @param  array<string, mixed>  $logging
     * @param  array<string, mixed>  $delivery
     * @return array<int, string>
     */
    private function recommendations(array $preflight, array $alerts, array $logging, array $delivery, array $recovery, array $certification, array $promotion, array $cutover, array $operationalCertification): array
    {
        $items = [];

        if (($preflight['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:preflight --json and remediate critical platform checks before promoting the release.';
        }

        $frontendUatReleaseGate = (array) ($preflight['checks']['frontend_uat_release_gate'] ?? []);

        if (($frontendUatReleaseGate['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan frontend:uat-release-readiness --json and complete signed visual UAT evidence before frontend cutover.';
        }

        if (($alerts['status'] ?? 'ok') === 'critical' && (int) ($delivery['candidate_alert_count'] ?? 0) > 0) {
            $items[] = 'Validate outbound alert delivery now with php artisan system:dispatch-alerts --json --force.';
        }

        if (! (bool) ($logging['structured_logging_enabled'] ?? false)) {
            $items[] = 'Enable stderr_json or daily_json in the effective logging stack for production-like environments.';
        }

        foreach ((array) ($delivery['channels'] ?? []) as $channel) {
            if (($channel['status'] ?? 'warning') !== 'ready') {
                $items[] = sprintf(
                    'Complete configuration for alert channel [%s] before relying on it for incident response.',
                    (string) ($channel['channel'] ?? 'unknown'),
                );
            }
        }

        if (($recovery['backup']['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:backup-readiness --json and record a fresh backup manifest before the next production release.';
        }

        if (($recovery['restore_drill']['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:restore-drill --json and review the latest restore drill report before the next production release.';
        }

        if (($certification['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:staging-certification --json and refresh the staging certification evidence before promoting the current release.';
        }

        if (($promotion['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:promotion-readiness --json and record release promotion evidence before the production go-live decision.';
        }

        if (($cutover['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:cutover-readiness --json and record the final cutover decision before switching production traffic.';
        }

        if (($operationalCertification['status'] ?? 'ok') !== 'ok') {
            $items[] = 'Run php artisan system:operational-certification --json and record operational certification evidence before treating the release as fully governed by deploy, rollback, backup, and restore proofs.';
        }

        return array_values(array_unique($items));
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
