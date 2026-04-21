<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JsonException;

class OperationalCertificationService
{
    public function __construct(
        private readonly SystemPreflightService $preflight,
        private readonly SystemAlertService $alerts,
        private readonly BackupRecoveryService $backupRecovery,
        private readonly ReleasePromotionService $promotion,
        private readonly ReleaseCutoverService $cutover,
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
        $latestCertificate = $this->latestCertificateManifest($config);
        $preflight = is_array($signals['preflight'] ?? null) ? $signals['preflight'] : $this->preflight->summary();
        $alerts = is_array($signals['alerts'] ?? null) ? $signals['alerts'] : $this->alerts->summary($signals['date'] ?? null);
        $recovery = is_array($signals['recovery'] ?? null) ? $signals['recovery'] : $this->backupRecovery->observabilitySummary();
        $promotion = is_array($signals['promotion'] ?? null)
            ? $signals['promotion']
            : $this->promotion->summary([
                'date' => $signals['date'] ?? null,
                'preflight' => $preflight,
                'alerts' => $alerts,
                'recovery' => $recovery,
            ]);
        $cutover = is_array($signals['cutover'] ?? null)
            ? $signals['cutover']
            : $this->cutover->summary([
                'date' => $signals['date'] ?? null,
                'preflight' => $preflight,
                'alerts' => $alerts,
                'recovery' => $recovery,
                'promotion' => $promotion,
            ]);

        $gateItems = $required
            ? $this->gateItems($preflight, $alerts, $recovery, $promotion, $cutover, $releaseIdentifier)
            : [];
        $evidenceItems = [];

        if ($required) {
            $evidenceItems = array_merge($evidenceItems, $this->pathChecks(
                $storagePath,
                'operational_certification_storage',
                'Operational certification evidence path must exist and be writable.',
            ));

            $evidenceItems = array_merge($evidenceItems, $this->pathChecks(
                $historyPath,
                'operational_certification_history',
                'Operational certification history path must exist and be writable.',
            ));

            if ($latestCertificate === null) {
                $evidenceItems[] = [
                    'severity' => 'warning',
                    'code' => 'operational_certification_evidence_missing',
                    'message' => 'No operational certification has been recorded for this environment.',
                    'action' => 'Run php artisan system:record-operational-certification after validating deploy, rollback, backup, restore, promotion, and cutover evidence for the current release.',
                ];
            } elseif (($latestCertificate['status'] ?? 'ok') !== 'certified') {
                $evidenceItems[] = [
                    'severity' => 'critical',
                    'code' => 'operational_certification_evidence_invalid',
                    'message' => 'The latest operational certification manifest is unreadable or invalid.',
                    'action' => 'Repair or replace the operational certification manifest before using it as release evidence.',
                    'metric_snapshot' => [
                        'manifest_path' => $latestCertificate['manifest_path'] ?? null,
                        'reason' => $latestCertificate['reason'] ?? null,
                    ],
                ];
            } else {
                $certifiedAt = Carbon::parse((string) ($latestCertificate['certified_at'] ?? now()->toIso8601String()));
                $freshnessHours = max(1, (int) ($config['freshness_hours'] ?? 24));

                if ($certifiedAt->lt(now()->subHours($freshnessHours))) {
                    $evidenceItems[] = [
                        'severity' => 'warning',
                        'code' => 'operational_certification_evidence_stale',
                        'message' => 'The latest operational certification is older than the configured freshness window.',
                        'action' => 'Refresh operational certification evidence before the next controlled promotion or production change window.',
                        'metric_snapshot' => [
                            'certified_at' => $certifiedAt->toIso8601String(),
                            'freshness_hours' => $freshnessHours,
                        ],
                    ];
                }

                if ($releaseIdentifier !== ''
                    && trim((string) ($latestCertificate['release'] ?? '')) !== $releaseIdentifier) {
                    $evidenceItems[] = [
                        'severity' => 'warning',
                        'code' => 'operational_certification_evidence_release_mismatch',
                        'message' => 'The latest operational certification evidence does not match the configured release identifier.',
                        'action' => 'Record fresh operational certification evidence for the current release before using it as the go-live gate.',
                        'metric_snapshot' => [
                            'expected_release' => $releaseIdentifier,
                            'certified_release' => $latestCertificate['release'] ?? null,
                        ],
                    ];
                }

                if (trim((string) ($latestCertificate['environment'] ?? '')) !== $environment) {
                    $evidenceItems[] = [
                        'severity' => 'warning',
                        'code' => 'operational_certification_evidence_environment_mismatch',
                        'message' => 'The latest operational certification was recorded for a different environment.',
                        'action' => 'Record operational certification again from the environment that will carry the release.',
                        'metric_snapshot' => [
                            'expected_environment' => $environment,
                            'certified_environment' => $latestCertificate['environment'] ?? null,
                        ],
                    ];
                }
            }
        }

        $gateStatus = $this->resolveStatus($gateItems);
        $status = $this->resolveStatus(array_merge($gateItems, $evidenceItems));

        return [
            'status' => $status,
            'operationally_certified' => $gateStatus === 'ok',
            'certificate_recorded' => $latestCertificate !== null
                && ($latestCertificate['status'] ?? 'ok') === 'certified'
                && ($releaseIdentifier === '' || trim((string) ($latestCertificate['release'] ?? '')) === $releaseIdentifier)
                && trim((string) ($latestCertificate['environment'] ?? '')) === $environment,
            'checked_at' => now()->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $config['expected_environment'] ?? 'production',
            'required' => $required,
            'required_environments' => $requiredEnvironments,
            'release_identifier' => $releaseIdentifier !== '' ? $releaseIdentifier : null,
            'storage_path' => $storagePath !== '' ? $storagePath : null,
            'history_path' => $historyPath !== '' ? $historyPath : null,
            'manifest_path' => $this->manifestPath($config),
            'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery, $promotion, $cutover),
            'latest_certificate' => $latestCertificate,
            'items' => array_merge($gateItems, $evidenceItems),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordCertification(
        string $release,
        string $deployEvidence,
        string $rollbackEvidence,
        string $backupArtifact,
        string $restoreEvidence,
        ?string $monitoringEvidence = null,
        ?string $operator = null,
        ?string $notes = null,
        ?string $certifiedAt = null,
        ?string $date = null,
        bool $allowWarnings = false,
    ): array {
        $config = $this->config();
        $environment = $this->currentEnvironment();
        $expectedEnvironment = trim((string) ($config['expected_environment'] ?? 'production'));
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
                    'code' => 'operational_certification_paths_not_configured',
                    'message' => 'Operational certification evidence paths are not configured.',
                    'action' => 'Set VELMIX_OPERATIONAL_CERTIFICATION_STORAGE_PATH and VELMIX_OPERATIONAL_CERTIFICATION_HISTORY_PATH before recording operational certification evidence.',
                ]],
            ];
        }

        if ($expectedEnvironment !== '' && $environment !== $expectedEnvironment) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => [[
                    'severity' => 'critical',
                    'code' => 'operational_certification_environment_mismatch',
                    'message' => 'Operational certification can only be recorded from the expected target environment.',
                    'action' => sprintf('Run this command from APP_ENV=%s or override the expected operational certification environment deliberately.', $expectedEnvironment),
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
                    'code' => 'operational_certification_release_identifier_mismatch',
                    'message' => 'The provided release does not match the configured release identifier.',
                    'action' => 'Record operational certification evidence only for the release currently configured on this node.',
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
        $promotion = $this->promotion->summary([
            'date' => $date,
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
        ]);
        $cutover = $this->cutover->summary([
            'date' => $date,
            'preflight' => $preflight,
            'alerts' => $alerts,
            'recovery' => $recovery,
            'promotion' => $promotion,
        ]);

        $gateItems = $this->gateItems($preflight, $alerts, $recovery, $promotion, $cutover, $releaseIdentifier);
        $hasCriticalItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'critical');
        $hasWarningItems = collect($gateItems)->contains(fn (array $item) => $item['severity'] === 'warning');

        if ($hasCriticalItems || ($hasWarningItems && ! $allowWarnings)) {
            return [
                'status' => 'blocked',
                'checked_at' => now()->toIso8601String(),
                'items' => $gateItems,
                'current_gates' => $this->gateSnapshot($preflight, $alerts, $recovery, $promotion, $cutover),
            ];
        }

        $latestBackup = $recovery['backup']['latest_backup'] ?? null;
        $latestRestoreDrill = $recovery['restore_drill']['latest_drill'] ?? null;
        $latestPromotion = $promotion['latest_approval'] ?? null;
        $latestCutover = $cutover['latest_decision'] ?? null;
        $certifiedTimestamp = $certifiedAt !== null
            ? Carbon::parse($certifiedAt)
            : now();
        $manifest = [
            'status' => 'certified',
            'recorded_at' => now()->toIso8601String(),
            'certified_at' => $certifiedTimestamp->toIso8601String(),
            'environment' => $environment,
            'expected_environment' => $expectedEnvironment !== '' ? $expectedEnvironment : null,
            'release' => $release,
            'release_identifier' => $releaseIdentifier !== '' ? $releaseIdentifier : null,
            'deploy_evidence' => trim($deployEvidence),
            'rollback_evidence' => trim($rollbackEvidence),
            'backup_artifact' => trim($backupArtifact),
            'restore_evidence' => trim($restoreEvidence),
            'monitoring_evidence' => $monitoringEvidence !== null && trim($monitoringEvidence) !== '' ? trim($monitoringEvidence) : null,
            'operator' => $operator !== null && trim($operator) !== '' ? trim($operator) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'allow_warning_override' => $allowWarnings,
            'checks' => $this->gateSnapshot($preflight, $alerts, $recovery, $promotion, $cutover),
            'backup_manifest_path' => $latestBackup['manifest_path'] ?? null,
            'restore_drill_report_path' => $latestRestoreDrill['report_path'] ?? null,
            'release_promotion_manifest_path' => $latestPromotion['manifest_path'] ?? null,
            'release_cutover_manifest_path' => $latestCutover['manifest_path'] ?? null,
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = $this->manifestPath($config);
        $historyManifestPath = $historyPath.DIRECTORY_SEPARATOR.sprintf(
            'operational-certification-%s.json',
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
        return (array) config('velmix.operational_certification', []);
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
    private function latestCertificateManifest(array $config): ?array
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
                'reason' => 'Operational certification payload is not an object.',
            ];
        }

        try {
            Carbon::parse((string) ($data['certified_at'] ?? ''));
        } catch (\Throwable) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => 'Operational certification timestamp is invalid.',
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
                'code' => 'operational_certification_path_not_configured',
                'message' => sprintf('A required operational certification path is not configured: %s.', $label),
                'action' => 'Define the required operational certification paths before using controlled release evidence.',
            ]];
        }

        if (! File::isDirectory($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'operational_certification_path_missing',
                'message' => $message,
                'action' => 'Create the path and ensure the application user can write to it before recording operational certification evidence.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        if (! is_writable($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'operational_certification_path_not_writable',
                'message' => $message,
                'action' => 'Grant write permissions to the application user before recording operational certification evidence.',
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
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $cutover
     * @return array<string, mixed>
     */
    private function gateSnapshot(array $preflight, array $alerts, array $recovery, array $promotion, array $cutover): array
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
            'promotion' => [
                'status' => $promotion['status'] ?? 'unknown',
                'approval_recorded' => $promotion['approval_recorded'] ?? false,
                'latest_approval' => $promotion['latest_approval'] ?? null,
            ],
            'cutover' => [
                'status' => $cutover['status'] ?? 'unknown',
                'decision_recorded' => $cutover['decision_recorded'] ?? false,
                'latest_decision' => $cutover['latest_decision'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $alerts
     * @param  array<string, mixed>  $recovery
     * @param  array<string, mixed>  $promotion
     * @param  array<string, mixed>  $cutover
     * @return array<int, array<string, mixed>>
     */
    private function gateItems(array $preflight, array $alerts, array $recovery, array $promotion, array $cutover, string $releaseIdentifier): array
    {
        $items = [];
        $preflightStatus = (string) ($preflight['status'] ?? 'ok');
        $alertsStatus = (string) ($alerts['status'] ?? 'ok');
        $backupStatus = (string) ($recovery['backup']['status'] ?? 'ok');
        $restoreDrillStatus = (string) ($recovery['restore_drill']['status'] ?? 'ok');
        $promotionStatus = (string) ($promotion['status'] ?? 'ok');
        $cutoverStatus = (string) ($cutover['status'] ?? 'ok');
        $promotionApprovalRecorded = (bool) ($promotion['approval_recorded'] ?? false);
        $cutoverDecisionRecorded = (bool) ($cutover['decision_recorded'] ?? false);
        $approvedRelease = trim((string) ($promotion['latest_approval']['release'] ?? ''));
        $cutoverRelease = trim((string) ($cutover['latest_decision']['release'] ?? ''));

        if ($releaseIdentifier === '') {
            $items[] = [
                'severity' => 'critical',
                'code' => 'operational_certification_release_identifier_missing',
                'message' => 'No release identifier is configured for operational certification.',
                'action' => 'Set VELMIX_RELEASE_IDENTIFIER before using operational certification as a controlled go-live gate.',
            ];
        }

        if ($preflightStatus !== 'ok') {
            $items[] = [
                'severity' => $preflightStatus,
                'code' => 'operational_certification_preflight_not_ok',
                'message' => 'System preflight is not green for operational certification.',
                'action' => 'Resolve preflight findings before certifying this release on the target environment.',
            ];
        }

        if ($alertsStatus !== 'ok') {
            $items[] = [
                'severity' => $alertsStatus,
                'code' => 'operational_certification_alerts_not_ok',
                'message' => 'Operational alerts remain open while certifying the current release.',
                'action' => 'Investigate and clear critical or warning alerts before recording operational certification.',
                'metric_snapshot' => [
                    'critical_count' => (int) ($alerts['summary']['critical_count'] ?? 0),
                    'warning_count' => (int) ($alerts['summary']['warning_count'] ?? 0),
                ],
            ];
        }

        if ($backupStatus !== 'ok') {
            $items[] = [
                'severity' => $backupStatus,
                'code' => 'operational_certification_backup_not_ok',
                'message' => 'Backup posture is not healthy enough for operational certification.',
                'action' => 'Record a fresh backup manifest and remediate backup readiness findings first.',
            ];
        }

        if ($restoreDrillStatus !== 'ok') {
            $items[] = [
                'severity' => $restoreDrillStatus,
                'code' => 'operational_certification_restore_drill_not_ok',
                'message' => 'Restore drill posture is not healthy enough for operational certification.',
                'action' => 'Run a fresh restore drill and resolve any report issues first.',
            ];
        }

        if ($promotionStatus !== 'ok') {
            $items[] = [
                'severity' => $promotionStatus === 'warning' ? 'critical' : $promotionStatus,
                'code' => 'operational_certification_promotion_not_ok',
                'message' => 'Release promotion posture is not healthy enough for operational certification.',
                'action' => 'Refresh release promotion evidence for the current release before certifying it.',
            ];
        }

        if (! $promotionApprovalRecorded) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'operational_certification_promotion_not_recorded',
                'message' => 'No valid release promotion approval is recorded for the current release.',
                'action' => 'Record release promotion approval evidence before operational certification.',
            ];
        } elseif ($releaseIdentifier !== '' && $approvedRelease !== $releaseIdentifier) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'operational_certification_promotion_release_mismatch',
                'message' => 'The latest release promotion evidence does not match the configured release identifier.',
                'action' => 'Record fresh release promotion evidence for the current release before operational certification.',
                'metric_snapshot' => [
                    'expected_release' => $releaseIdentifier,
                    'approved_release' => $approvedRelease !== '' ? $approvedRelease : null,
                ],
            ];
        }

        if ($cutoverStatus !== 'ok') {
            $items[] = [
                'severity' => $cutoverStatus === 'warning' ? 'critical' : $cutoverStatus,
                'code' => 'operational_certification_cutover_not_ok',
                'message' => 'Release cutover posture is not healthy enough for operational certification.',
                'action' => 'Refresh cutover evidence for the current release before operational certification.',
            ];
        }

        if (! $cutoverDecisionRecorded) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'operational_certification_cutover_not_recorded',
                'message' => 'No valid release cutover decision is recorded for the current release.',
                'action' => 'Record release cutover evidence before operational certification.',
            ];
        } elseif ($releaseIdentifier !== '' && $cutoverRelease !== $releaseIdentifier) {
            $items[] = [
                'severity' => 'critical',
                'code' => 'operational_certification_cutover_release_mismatch',
                'message' => 'The latest release cutover evidence does not match the configured release identifier.',
                'action' => 'Record fresh release cutover evidence for the current release before operational certification.',
                'metric_snapshot' => [
                    'expected_release' => $releaseIdentifier,
                    'cutover_release' => $cutoverRelease !== '' ? $cutoverRelease : null,
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
}
