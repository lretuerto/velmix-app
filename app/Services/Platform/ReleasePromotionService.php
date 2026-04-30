<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JsonException;

class ReleasePromotionService
{
    public function __construct(
        private readonly SystemPreflightService $preflight,
        private readonly SystemAlertService $alerts,
        private readonly BackupRecoveryService $backupRecovery,
        private readonly StagingCertificationService $stagingCertification,
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
        $latestApproval = $this->latestApprovalManifest($config);
        $preflight = is_array($signals['preflight'] ?? null) ? $signals['preflight'] : $this->preflight->summary();
        $alerts = is_array($signals['alerts'] ?? null) ? $signals['alerts'] : $this->alerts->summary($signals['date'] ?? null);
        $recovery = is_array($signals['recovery'] ?? null) ? $signals['recovery'] : $this->backupRecovery->observabilitySummary();
        $certification = is_array($signals['certification'] ?? null)
            ? $signals['certification']
            : $this->stagingCertification->summary([
                'preflight' => $preflight,
                'alerts' => $alerts,
                'recovery' => $recovery,
            ]);

        $gateItems = $required
            ? $this->gateItems($preflight, $alerts, $recovery, $certification, $releaseIdentifier)
            : [];
        $evidenceItems = [];

        if ($required) {
            $evidenceItems = array_merge($evidenceItems, $this->pathChecks(
                $storagePath,
                'release_promotion_storage',
                'Release promotion evidence path must exist and be writable.',
            ));

            $evidenceItems = array_merge($evidenceItems, $this->pathChecks(
                $historyPath,
                'release_promotion_history',
                'Release promotion history path must exist and be writable.',
            ));

            if ($latestApproval === null) {
                $evidenceItems[] = [
                    'severity' => 'warning',
                    'code' => 'release_promotion_evidence_missing',
                    'message' => 'No release promotion approval has been recorded for this environment.',
                    'action' => 'Run php artisan system:record-release-promotion after validating the release candidate and rollback evidence.',
                ];
            } elseif (($latestApproval['status'] ?? 'ok') !== 'approved') {
                $evidenceItems[] = [
                    'severity' => 'critical',
                    'code' => 'release_promotion_evidence_invalid',
                    'message' => 'The latest release promotion manifest is unreadable or invalid.',
                    'action' => 'Repair or replace the release promotion manifest before using it as go-live evidence.',
                    'metric_snapshot' => [
                        'manifest_path' => $latestApproval['manifest_path'] ?? null,
                        'reason' => $latestApproval['reason'] ?? null,
                    ],
                ];
            } else {
                $approvedAt = Carbon::parse((string) ($latestApproval['approved_at'] ?? now()->toIso8601String()));
                $freshnessHours = max(1, (int) ($config['freshness_hours'] ?? 72));

                if ($approvedAt->lt(now()->subHours($freshnessHours))) {
                    $evidenceItems[] = [
                        'severity' => 'warning',
                        'code' => 'release_promotion_evidence_stale',
                        'message' => 'The latest release promotion approval is older than the configured freshness window.',
                        'action' => 'Refresh release promotion approval evidence before the next production go-live window.',
                        'metric_snapshot' => [
                            'approved_at' => $approvedAt->toIso8601String(),
                            'freshness_hours' => $freshnessHours,
                        ],
                    ];
                }

                if ($releaseIdentifier !== ''
                    && trim((string) ($latestApproval['release'] ?? '')) !== $releaseIdentifier) {
                    $evidenceItems[] = [
                        'severity' => 'warning',
                        'code' => 'release_promotion_evidence_release_mismatch',
                        'message' => 'The latest release promotion evidence does not match the configured release identifier.',
                        'action' => 'Record fresh release promotion evidence for the current release before go-live.',
                        'metric_snapshot' => [
                            'expected_release' => $releaseIdentifier,
                            'approved_release' => $latestApproval['release'] ?? null,
                        ],
                    ];
                }
            }
        }

        $gateStatus = $this->resolveStatus($gateItems);
        $status = $this->resolveStatus(array_merge($gateItems, $evidenceItems));

        return [
            'status' => $status,
            'promotable' => $gateStatus === 'ok',
            'approval_recorded' => $latestApproval !== null
                && ($latestApproval['status'] ?? 'ok') === 'approved'
                && ($releaseIdentifier === '' || trim((string) ($latestApproval['release'] ?? '')) === $releaseIdentifier),
            'checked_at' => now()->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $config['expected_environment'] ?? 'staging',
            'required' => $required,
            'required_environments' => $requiredEnvironments,
            'release_identifier' => $releaseIdentifier !== '' ? $releaseIdentifier : null,
            'storage_path' => $storagePath !== '' ? $storagePath : null,
            'history_path' => $historyPath !== '' ? $historyPath : null,
            'manifest_path' => $this->manifestPath($config),
            'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery, $certification),
            'latest_approval' => $latestApproval,
            'items' => array_merge($gateItems, $evidenceItems),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordApproval(
        string $release,
        string $approvalEvidence,
        string $rollbackEvidence,
        ?string $operator = null,
        ?string $notes = null,
        ?string $approvedAt = null,
        ?string $date = null,
        bool $allowWarnings = false,
    ): array {
        $config = $this->config();
        $environment = $this->currentEnvironment();
        $expectedEnvironment = trim((string) ($config['expected_environment'] ?? 'staging'));
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $historyPath = $this->normalizePath((string) ($config['history_path'] ?? ''));
        $releaseIdentifier = trim((string) ($config['release_identifier'] ?? ''));
        $release = trim($release);

        if ($storagePath === '' || $historyPath === '') {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'release_promotion_paths_not_configured',
                    'message' => 'Release promotion evidence paths are not configured.',
                    'action' => 'Set VELMIX_RELEASE_PROMOTION_STORAGE_PATH and VELMIX_RELEASE_PROMOTION_HISTORY_PATH before recording approval evidence.',
                ]],
            ];
        }

        if ($expectedEnvironment !== '' && $environment !== $expectedEnvironment) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'release_promotion_environment_mismatch',
                    'message' => 'Release promotion approval can only be recorded from the expected promotion environment.',
                    'action' => sprintf('Run this command from APP_ENV=%s or override the expected promotion environment deliberately.', $expectedEnvironment),
                    'metric_snapshot' => [
                        'current_environment' => $environment,
                        'expected_environment' => $expectedEnvironment,
                    ],
                ]],
            ];
        }

        if ($releaseIdentifier !== '' && $release !== $releaseIdentifier) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'release_promotion_release_identifier_mismatch',
                    'message' => 'The provided release does not match the configured release identifier.',
                    'action' => 'Record promotion evidence only for the release currently configured on this node.',
                    'metric_snapshot' => [
                        'provided_release' => $release,
                        'configured_release' => $releaseIdentifier,
                    ],
                ]],
            ];
        }

        File::ensureDirectoryExists($storagePath);
        File::ensureDirectoryExists($historyPath);

        $preflight = $this->preflight->summary();
        $alerts = $this->alerts->summary($date);
        $recovery = $this->backupRecovery->observabilitySummary();
        $certification = $this->stagingCertification->summary([
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
        ]);
        $gateItems = $this->gateItems($preflight, $alerts, $recovery, $certification, $releaseIdentifier);
        $hasCriticalItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'critical');
        $hasWarningItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'warning');

        if ($hasCriticalItems || ($hasWarningItems && ! $allowWarnings)) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => $gateItems,
                'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery, $certification),
            ];
        }

        $latestCertification = $certification['latest_certification'] ?? null;
        $latestBackup = $recovery['backup']['latest_backup'] ?? null;
        $latestRestoreDrill = $recovery['restore_drill']['latest_drill'] ?? null;
        $approvedTimestamp = $approvedAt !== null
            ? Carbon::parse($approvedAt)
            : now();
        $manifest = [
            'status' => 'approved',
            'recorded_at' => now()->toIso8601String(),
            'approved_at' => $approvedTimestamp->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $expectedEnvironment !== '' ? $expectedEnvironment : null,
            'release' => $release,
            'release_identifier' => $releaseIdentifier !== '' ? $releaseIdentifier : null,
            'approval_evidence' => trim($approvalEvidence),
            'rollback_evidence' => trim($rollbackEvidence),
            'operator' => $operator !== null && trim($operator) !== '' ? trim($operator) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'allow_warning_override' => $allowWarnings,
            'checks' => $this->gateSnapshot($preflight, $alerts, $recovery, $certification),
            'staging_certification_manifest_path' => $latestCertification['manifest_path'] ?? null,
            'backup_manifest_path' => $latestBackup['manifest_path'] ?? null,
            'restore_drill_report_path' => $latestRestoreDrill['report_path'] ?? null,
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = $this->manifestPath($config);
        $historyManifestPath = $historyPath.DIRECTORY_SEPARATOR.sprintf(
            'release-promotion-%s.json',
            $approvedTimestamp->format('Ymd-His')
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
        $config = (array) config('velmix.release_promotion', []);

        if (($expectedEnvironment = $this->runtimeEnv('VELMIX_RELEASE_PROMOTION_ENV')) !== null) {
            $config['expected_environment'] = $expectedEnvironment;
        }

        if (($requiredEnvironments = $this->runtimeEnvList('VELMIX_RELEASE_PROMOTION_REQUIRED_ENVS')) !== null) {
            $config['required_environments'] = $requiredEnvironments;
        }

        if (($releaseIdentifier = $this->runtimeEnv('VELMIX_RELEASE_IDENTIFIER')) !== null) {
            $config['release_identifier'] = $releaseIdentifier;
        }

        return $config;
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

    /**
     * @param  array<string, mixed>  $config
     */
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
    private function latestApprovalManifest(array $config): ?array
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
                'reason' => 'Release promotion payload is not an object.',
            ];
        }

        try {
            Carbon::parse((string) ($data['approved_at'] ?? ''));
        } catch (\Throwable $exception) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => 'Release promotion timestamp is invalid.',
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
                'code' => 'release_promotion_path_not_configured',
                'message' => sprintf('A required release promotion path is not configured: %s.', $label),
                'action' => 'Define the required release promotion paths before using approval evidence as a go-live gate.',
            ]];
        }

        if (! File::isDirectory($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'release_promotion_path_missing',
                'message' => $message,
                'action' => 'Create the path and ensure the application user can write to it before recording promotion approval evidence.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        if (! is_writable($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'release_promotion_path_not_writable',
                'message' => $message,
                'action' => 'Grant write permissions to the application user before recording promotion approval evidence.',
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
     * @param  array<string, mixed>  $certification
     * @return array<string, mixed>
     */
    private function gateSnapshot(array $preflight, array $alerts, array $recovery, array $certification): array
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
            'staging_certification' => [
                'status' => $certification['status'] ?? 'unknown',
                'latest_certification' => $certification['latest_certification'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     * @param  array<string, mixed>  $recovery
     * @param  array<string, mixed>  $certification
     * @return array<int, array<string, mixed>>
     */
    private function gateItems(array $preflight, array $alerts, array $recovery, array $certification, string $releaseIdentifier): array
    {
        $items = [];
        $preflightStatus = (string) ($preflight['status'] ?? 'ok');
        $alertsStatus = (string) ($alerts['status'] ?? 'ok');
        $backupStatus = (string) ($recovery['backup']['status'] ?? 'ok');
        $restoreDrillStatus = (string) ($recovery['restore_drill']['status'] ?? 'ok');
        $certificationStatus = (string) ($certification['status'] ?? 'ok');
        $certifiedRelease = trim((string) ($certification['latest_certification']['release'] ?? ''));

        if ($releaseIdentifier === '') {
            $items[] = [
                'severity' => 'critical',
                'code' => 'release_promotion_release_identifier_missing',
                'message' => 'No release identifier is configured for promotion readiness.',
                'action' => 'Set VELMIX_RELEASE_IDENTIFIER before using release promotion evidence as a go-live gate.',
            ];
        }

        if ($preflightStatus !== 'ok') {
            $items[] = [
                'severity' => $preflightStatus,
                'code' => 'release_promotion_preflight_not_ok',
                'message' => 'System preflight is not green for release promotion.',
                'action' => 'Resolve preflight findings before treating the release as promotable.',
            ];
        }

        if ($alertsStatus !== 'ok') {
            $items[] = [
                'severity' => $alertsStatus,
                'code' => 'release_promotion_alerts_not_ok',
                'message' => 'Operational alerts remain open while attempting to promote the release.',
                'action' => 'Investigate and clear critical or warning alerts before go-live.',
                'metric_snapshot' => [
                    'critical_count' => (int) ($alerts['summary']['critical_count'] ?? 0),
                    'warning_count' => (int) ($alerts['summary']['warning_count'] ?? 0),
                ],
            ];
        }

        if ($backupStatus !== 'ok') {
            $items[] = [
                'severity' => $backupStatus,
                'code' => 'release_promotion_backup_not_ok',
                'message' => 'Backup posture is not healthy for release promotion.',
                'action' => 'Record a fresh backup manifest and remediate backup readiness findings first.',
            ];
        }

        if ($restoreDrillStatus !== 'ok') {
            $items[] = [
                'severity' => $restoreDrillStatus,
                'code' => 'release_promotion_restore_drill_not_ok',
                'message' => 'Restore drill posture is not healthy for release promotion.',
                'action' => 'Run a fresh restore drill and resolve any report issues first.',
            ];
        }

        if ($certificationStatus !== 'ok') {
            $items[] = [
                'severity' => $certificationStatus === 'warning' ? 'critical' : $certificationStatus,
                'code' => 'release_promotion_staging_certification_not_ok',
                'message' => 'Staging certification is not healthy enough to support release promotion.',
                'action' => 'Refresh staging certification evidence for the current release before go-live.',
            ];
        } elseif ($releaseIdentifier !== '' && $certifiedRelease !== $releaseIdentifier) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'release_promotion_staging_release_mismatch',
                'message' => 'The latest staging certification does not match the configured release identifier.',
                'action' => 'Certify staging again for the current release before promoting it.',
                'metric_snapshot' => [
                    'expected_release' => $releaseIdentifier,
                    'certified_release' => $certifiedRelease !== '' ? $certifiedRelease : null,
                ],
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

    private function runtimeEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === null) {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array<int, string>|null
     */
    private function runtimeEnvList(string $name): ?array
    {
        $value = $this->runtimeEnv($name);

        if ($value === null) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (string $environment) => trim($environment),
            explode(',', $value)
        )));
    }
}
