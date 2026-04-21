<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JsonException;

class StagingCertificationService
{
    public function __construct(
        private readonly SystemPreflightService $preflight,
        private readonly SystemAlertService $alerts,
        private readonly BackupRecoveryService $backupRecovery,
    ) {}

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    public function summary(array $signals = []): array
    {
        $config = $this->config();
        $environment = $this->currentEnvironment();
        $requiredEnvironments = $this->requiredEnvironments($config);
        $required = in_array($environment, $requiredEnvironments, true);
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $historyPath = $this->normalizePath((string) ($config['history_path'] ?? ''));
        $releaseIdentifier = trim((string) ($config['release_identifier'] ?? ''));
        $latestCertification = $this->latestCertificationManifest($config);
        $preflight = is_array($signals['preflight'] ?? null) ? $signals['preflight'] : $this->preflight->summary();
        $alerts = is_array($signals['alerts'] ?? null) ? $signals['alerts'] : $this->alerts->summary();
        $recovery = is_array($signals['recovery'] ?? null) ? $signals['recovery'] : $this->backupRecovery->observabilitySummary();
        $items = [];

        if ($required) {
            $items = array_merge($items, $this->pathChecks(
                $storagePath,
                'staging_certification_storage',
                'Staging certification storage path must exist and be writable.',
            ));

            $items = array_merge($items, $this->pathChecks(
                $historyPath,
                'staging_certification_history',
                'Staging certification history path must exist and be writable.',
            ));

            if ($latestCertification === null) {
                $items[] = [
                    'severity' => 'warning',
                    'code' => 'staging_certification_missing',
                    'message' => 'No staging certification manifest has been recorded for this environment.',
                    'action' => 'Run php artisan system:record-staging-certification after validating deploy, rollback, backup, and restore drill evidence in staging.',
                ];
            } elseif (($latestCertification['status'] ?? 'ok') !== 'certified') {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'staging_certification_invalid',
                    'message' => 'The latest staging certification manifest is unreadable or invalid.',
                    'action' => 'Repair or replace the staging certification manifest before treating this environment as release-ready.',
                    'metric_snapshot' => [
                        'manifest_path' => $latestCertification['manifest_path'] ?? null,
                        'reason' => $latestCertification['reason'] ?? null,
                    ],
                ];
            } else {
                $certifiedAt = Carbon::parse((string) ($latestCertification['certified_at'] ?? now()->toIso8601String()));
                $freshnessHours = max(1, (int) ($config['freshness_hours'] ?? 168));

                if ($certifiedAt->lt(now()->subHours($freshnessHours))) {
                    $items[] = [
                        'severity' => 'warning',
                        'code' => 'staging_certification_stale',
                        'message' => 'The latest staging certification is older than the configured freshness window.',
                        'action' => 'Repeat the staging deploy, rollback, backup, and restore drill validation before the next production promotion.',
                        'metric_snapshot' => [
                            'certified_at' => $certifiedAt->toIso8601String(),
                            'freshness_hours' => $freshnessHours,
                        ],
                    ];
                }

                if ($releaseIdentifier !== ''
                    && trim((string) ($latestCertification['release'] ?? '')) !== $releaseIdentifier) {
                    $items[] = [
                        'severity' => 'warning',
                        'code' => 'staging_certification_release_mismatch',
                        'message' => 'The latest staging certification does not match the configured release identifier for this environment.',
                        'action' => 'Record a fresh staging certification for the current release before treating it as promotable.',
                        'metric_snapshot' => [
                            'expected_release' => $releaseIdentifier,
                            'certified_release' => $latestCertification['release'] ?? null,
                        ],
                    ];
                }
            }
        }

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $config['expected_environment'] ?? 'staging',
            'required' => $required,
            'required_environments' => $requiredEnvironments,
            'release_identifier' => $releaseIdentifier !== '' ? $releaseIdentifier : null,
            'storage_path' => $storagePath !== '' ? $storagePath : null,
            'history_path' => $historyPath !== '' ? $historyPath : null,
            'manifest_path' => $this->manifestPath($config),
            'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery),
            'latest_certification' => $latestCertification,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordCertification(
        string $release,
        string $deployEvidence,
        string $rollbackEvidence,
        ?string $smokeEvidence = null,
        ?string $backupArtifact = null,
        ?string $operator = null,
        ?string $notes = null,
        ?string $certifiedAt = null,
        bool $allowWarnings = false,
    ): array {
        $config = $this->config();
        $environment = $this->currentEnvironment();
        $expectedEnvironment = trim((string) ($config['expected_environment'] ?? 'staging'));
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $historyPath = $this->normalizePath((string) ($config['history_path'] ?? ''));

        if ($storagePath === '' || $historyPath === '') {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'staging_certification_paths_not_configured',
                    'message' => 'Staging certification paths are not configured.',
                    'action' => 'Set VELMIX_STAGING_CERTIFICATION_STORAGE_PATH and VELMIX_STAGING_CERTIFICATION_HISTORY_PATH before recording certification evidence.',
                ]],
            ];
        }

        if ($expectedEnvironment !== '' && $environment !== $expectedEnvironment) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'staging_certification_environment_mismatch',
                    'message' => 'Staging certification can only be recorded from the expected staging environment.',
                    'action' => sprintf('Run this command from APP_ENV=%s or override the expected environment deliberately.', $expectedEnvironment),
                    'metric_snapshot' => [
                        'current_environment' => $environment,
                        'expected_environment' => $expectedEnvironment,
                    ],
                ]],
            ];
        }

        File::ensureDirectoryExists($storagePath);
        File::ensureDirectoryExists($historyPath);

        $preflight = $this->preflight->summary();
        $alerts = $this->alerts->summary();
        $recovery = $this->backupRecovery->observabilitySummary();
        $gateItems = $this->gateItems($preflight, $alerts, $recovery);
        $hasCriticalItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'critical');
        $hasWarningItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'warning');

        if ($hasCriticalItems || ($hasWarningItems && ! $allowWarnings)) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => $gateItems,
                'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery),
            ];
        }

        $latestBackup = $recovery['backup']['latest_backup'] ?? null;
        $latestRestoreDrill = $recovery['restore_drill']['latest_drill'] ?? null;
        $certifiedTimestamp = $certifiedAt !== null
            ? Carbon::parse($certifiedAt)
            : now();
        $manifest = [
            'status' => 'certified',
            'recorded_at' => now()->toIso8601String(),
            'certified_at' => $certifiedTimestamp->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $expectedEnvironment !== '' ? $expectedEnvironment : null,
            'release' => trim($release),
            'release_identifier' => trim((string) ($config['release_identifier'] ?? '')) ?: null,
            'deploy_evidence' => trim($deployEvidence),
            'rollback_evidence' => trim($rollbackEvidence),
            'smoke_evidence' => $smokeEvidence !== null && trim($smokeEvidence) !== '' ? trim($smokeEvidence) : null,
            'backup_artifact' => $backupArtifact !== null && trim($backupArtifact) !== ''
                ? trim($backupArtifact)
                : ($latestBackup['artifact'] ?? null),
            'backup_manifest_path' => $latestBackup['manifest_path'] ?? null,
            'restore_drill_report_path' => $latestRestoreDrill['report_path'] ?? null,
            'operator' => $operator !== null && trim($operator) !== '' ? trim($operator) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'allow_warning_override' => $allowWarnings,
            'checks' => $this->gateSnapshot($preflight, $alerts, $recovery),
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = $this->manifestPath($config);
        $historyManifestPath = $historyPath.DIRECTORY_SEPARATOR.sprintf(
            'staging-certification-%s.json',
            $certifiedTimestamp->format('Ymd-His')
        );

        File::put($manifestPath, $manifestJson);
        File::put($historyManifestPath, $manifestJson);

        return [
            'status' => 'recorded',
            'checked_at' => now()->toIso8601String(),
            'manifest_path' => $manifestPath,
            'history_manifest_path' => $historyManifestPath,
            'data' => $manifest,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('velmix.staging_certification', []);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    private function requiredEnvironments(array $config): array
    {
        $requiredEnvironments = $config['required_environments'] ?? [];

        if (is_string($requiredEnvironments)) {
            $requiredEnvironments = explode(',', $requiredEnvironments);
        }

        if (! is_array($requiredEnvironments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($environment) => trim((string) $environment),
            $requiredEnvironments
        )));
    }

    private function manifestPath(array $config): string
    {
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $manifestFilename = trim((string) ($config['manifest_filename'] ?? ''));

        if ($storagePath === '' || $manifestFilename === '') {
            return '';
        }

        return $storagePath.DIRECTORY_SEPARATOR.$manifestFilename;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function latestCertificationManifest(array $config): ?array
    {
        $manifestPath = $this->manifestPath($config);

        if ($manifestPath === '' || ! File::exists($manifestPath)) {
            return null;
        }

        try {
            $data = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => $exception->getMessage(),
            ];
        }

        if (! is_array($data)) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => 'Certification payload is not an object.',
            ];
        }

        try {
            Carbon::parse((string) ($data['certified_at'] ?? ''));
        } catch (\Throwable $exception) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => 'Certification timestamp is invalid.',
            ];
        }

        return array_merge($data, [
            'manifest_path' => $manifestPath,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathChecks(string $path, string $label, string $message): array
    {
        if ($path === '') {
            return [[
                'severity' => 'critical',
                'code' => 'staging_certification_path_not_configured',
                'message' => sprintf('A required staging certification path is not configured: %s.', $label),
                'action' => 'Define the required staging certification paths before relying on staging sign-off.',
            ]];
        }

        if (! File::isDirectory($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'staging_certification_path_missing',
                'message' => $message,
                'action' => 'Create the path and ensure the application user can write to it before recording staging certification evidence.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        if (! is_writable($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'staging_certification_path_not_writable',
                'message' => $message,
                'action' => 'Grant write permissions to the application user before recording staging certification evidence.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     * @param  array<string, mixed>  $recovery
     * @return array<string, mixed>
     */
    private function gateSnapshot(array $preflight, array $alerts, array $recovery): array
    {
        return [
            'preflight' => [
                'status' => $preflight['status'] ?? 'unknown',
                'item_count' => count($preflight['items'] ?? []),
            ],
            'alerts' => [
                'status' => $alerts['status'] ?? 'unknown',
                'critical_count' => (int) ($alerts['summary']['critical_count'] ?? 0),
                'warning_count' => (int) ($alerts['summary']['warning_count'] ?? 0),
            ],
            'backup' => [
                'status' => $recovery['backup']['status'] ?? 'unknown',
                'latest_backup' => $recovery['backup']['latest_backup'] ?? null,
            ],
            'restore_drill' => [
                'status' => $recovery['restore_drill']['status'] ?? 'unknown',
                'latest_drill' => $recovery['restore_drill']['latest_drill'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     * @param  array<string, mixed>  $recovery
     * @return array<int, array<string, mixed>>
     */
    private function gateItems(array $preflight, array $alerts, array $recovery): array
    {
        $items = [];
        $preflightStatus = (string) ($preflight['status'] ?? 'ok');
        $alertsStatus = (string) ($alerts['status'] ?? 'ok');
        $backupStatus = (string) ($recovery['backup']['status'] ?? 'ok');
        $restoreDrillStatus = (string) ($recovery['restore_drill']['status'] ?? 'ok');

        if ($preflightStatus !== 'ok') {
            $items[] = [
                'severity' => $preflightStatus,
                'code' => 'staging_certification_preflight_not_ok',
                'message' => 'System preflight is not green for staging certification.',
                'action' => 'Resolve preflight findings before recording staging sign-off evidence.',
            ];
        }

        if ($alertsStatus !== 'ok') {
            $items[] = [
                'severity' => $alertsStatus,
                'code' => 'staging_certification_alerts_not_ok',
                'message' => 'Operational alerts remain open while attempting to certify staging.',
                'action' => 'Investigate and clear critical or warning alerts before recording staging certification.',
            ];
        }

        if ($backupStatus !== 'ok') {
            $items[] = [
                'severity' => $backupStatus,
                'code' => 'staging_certification_backup_not_ok',
                'message' => 'Backup posture is not healthy for staging certification.',
                'action' => 'Record a fresh backup manifest and remediate backup readiness findings first.',
            ];
        }

        if ($restoreDrillStatus !== 'ok') {
            $items[] = [
                'severity' => $restoreDrillStatus,
                'code' => 'staging_certification_restore_drill_not_ok',
                'message' => 'Restore drill posture is not healthy for staging certification.',
                'action' => 'Run a fresh non-destructive restore drill and resolve any report issues first.',
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function resolveStatus(array $items): string
    {
        $resolved = null;

        foreach ($items as $item) {
            if (($item['severity'] ?? 'ok') === 'critical') {
                return 'critical';
            }

            if (($item['severity'] ?? 'ok') === 'warning') {
                $resolved = 'warning';
            }
        }

        return $resolved ?? 'ok';
    }

    private function normalizePath(string $path): string
    {
        return trim($path);
    }

    private function currentEnvironment(): string
    {
        return trim((string) config('app.env', app()->environment()));
    }
}
