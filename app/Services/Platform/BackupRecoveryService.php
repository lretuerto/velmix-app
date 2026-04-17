<?php

namespace App\Services\Platform;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class BackupRecoveryService
{
    public function backupReadinessSummary(): array
    {
        $config = $this->config();
        $items = [];
        $latestBackup = $this->latestBackupManifest($config);
        $appEnv = $this->currentEnvironment();
        $enabled = (bool) ($config['enabled'] ?? false);
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $historyPath = $this->normalizePath((string) ($config['history_path'] ?? ''));

        if (! $enabled && ! in_array($appEnv, ['local', 'testing'], true)) {
            $items[] = [
                'severity' => 'warning',
                'code' => 'backup_disabled',
                'message' => 'Backup recording is disabled in a non-local environment.',
                'action' => 'Enable VELMIX_BACKUP_ENABLED and record successful backup manifests before serving production traffic.',
            ];
        }

        if ($enabled) {
            $items = array_merge($items, $this->pathChecks(
                $storagePath,
                'backup_storage',
                'Backup storage path must exist and be writable.',
            ));

            $items = array_merge($items, $this->pathChecks(
                $historyPath,
                'backup_history',
                'Backup history path must exist and be writable.',
            ));

            if (! in_array($appEnv, ['local', 'testing'], true)
                && (bool) ($config['require_encryption'] ?? true)
                && trim((string) ($config['encryption_passphrase'] ?? '')) === '') {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'backup_encryption_passphrase_missing',
                    'message' => 'Backup encryption is required but no passphrase is configured.',
                    'action' => 'Set VELMIX_BACKUP_ENCRYPTION_PASSPHRASE before promoting the release.',
                ];
            }

            if ($latestBackup === null) {
                $items[] = [
                    'severity' => 'warning',
                    'code' => 'backup_manifest_missing',
                    'message' => 'No backup manifest has been recorded yet.',
                    'action' => 'Record the latest successful backup with php artisan system:record-backup after the backup job completes.',
                ];
            } elseif (($latestBackup['status'] ?? 'ok') !== 'ok') {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'backup_manifest_invalid',
                    'message' => 'The latest backup manifest is unreadable or invalid.',
                    'action' => 'Repair or replace the backup manifest before relying on restore procedures.',
                    'metric_snapshot' => [
                        'manifest_path' => $latestBackup['manifest_path'] ?? null,
                        'reason' => $latestBackup['reason'] ?? null,
                    ],
                ];
            } else {
                $generatedAt = Carbon::parse((string) ($latestBackup['generated_at'] ?? now()->toIso8601String()));
                $freshnessHours = max(1, (int) ($config['freshness_hours'] ?? 26));

                if ($generatedAt->lt(now()->subHours($freshnessHours))) {
                    $items[] = [
                        'severity' => 'warning',
                        'code' => 'backup_manifest_stale',
                        'message' => 'The latest recorded backup is older than the configured freshness window.',
                        'action' => 'Run or verify the scheduled backup job and record a fresh manifest before the next deployment window.',
                        'metric_snapshot' => [
                            'generated_at' => $generatedAt->toIso8601String(),
                            'freshness_hours' => $freshnessHours,
                        ],
                    ];
                }
            }
        }

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'enabled' => $enabled,
            'driver' => $config['driver'] ?? 'external',
            'storage_path' => $storagePath !== '' ? $storagePath : null,
            'history_path' => $historyPath !== '' ? $historyPath : null,
            'manifest_path' => $this->manifestPath($config),
            'latest_backup' => $latestBackup,
            'items' => $items,
        ];
    }

    public function recordBackup(
        string $artifact,
        ?string $checksum = null,
        ?int $sizeBytes = null,
        ?string $driver = null,
        ?string $generatedAt = null,
    ): array {
        $config = $this->config();
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $historyPath = $this->normalizePath((string) ($config['history_path'] ?? ''));

        if ($storagePath === '' || $historyPath === '') {
            throw new RuntimeException('Backup storage paths are not configured.');
        }

        File::ensureDirectoryExists($storagePath);
        File::ensureDirectoryExists($historyPath);

        $backupTimestamp = $generatedAt !== null
            ? Carbon::parse($generatedAt)
            : now();

        $manifest = [
            'status' => 'ok',
            'recorded_at' => now()->toIso8601String(),
            'generated_at' => $backupTimestamp->toIso8601String(),
            'artifact' => $artifact,
            'checksum' => $checksum,
            'size_bytes' => $sizeBytes,
            'driver' => $driver !== null && trim($driver) !== ''
                ? trim($driver)
                : (string) ($config['driver'] ?? 'external'),
            'app_env' => $this->currentEnvironment(),
            'database_connection' => (string) config('database.default'),
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $manifestPath = $this->manifestPath($config);
        $historyManifestPath = $historyPath.DIRECTORY_SEPARATOR.sprintf(
            'backup-%s.json',
            $backupTimestamp->format('Ymd-His')
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

    public function restoreDrillSummary(bool $persistReport = true): array
    {
        $config = $this->config();
        $enabled = (bool) ($config['enabled'] ?? false);
        $drillPath = $this->normalizePath((string) ($config['restore_drill_path'] ?? ''));
        $latestBackup = $this->latestBackupManifest($config);
        $latestDrill = $this->latestRestoreDrillReport($drillPath);
        $items = [];

        if ($enabled) {
            $items = array_merge($items, $this->pathChecks(
                $drillPath,
                'restore_drill_storage',
                'Restore drill path must exist and be writable.',
            ));

            if ($latestBackup === null || ($latestBackup['status'] ?? 'ok') !== 'ok') {
                $items[] = [
                    'severity' => 'warning',
                    'code' => 'restore_drill_backup_manifest_missing',
                    'message' => 'Restore drill cannot be validated because the latest backup manifest is unavailable.',
                    'action' => 'Record a successful backup before relying on restore drill execution.',
                ];
            }

            $maxAgeDays = max(1, (int) ($config['restore_drill_max_age_days'] ?? 30));

            if ($latestDrill === null) {
                $items[] = [
                    'severity' => 'warning',
                    'code' => 'restore_drill_missing',
                    'message' => 'No restore drill report has been recorded yet.',
                    'action' => 'Run php artisan system:restore-drill --json and retain the output before the next production release.',
                ];
            } elseif (($latestDrill['status'] ?? 'ok') !== 'ok') {
                $items[] = [
                    'severity' => 'critical',
                    'code' => 'restore_drill_invalid',
                    'message' => 'The latest restore drill report is unreadable or invalid.',
                    'action' => 'Repair or replace the restore drill report before treating recovery posture as healthy.',
                ];
            } else {
                $drillTimestamp = Carbon::parse((string) ($latestDrill['drilled_at'] ?? now()->toIso8601String()));

                if ($drillTimestamp->lt(now()->subDays($maxAgeDays))) {
                    $items[] = [
                        'severity' => 'warning',
                        'code' => 'restore_drill_stale',
                        'message' => 'The latest restore drill is older than the configured maximum age.',
                        'action' => 'Run a fresh restore drill and review the generated report before the next production release.',
                        'metric_snapshot' => [
                            'drilled_at' => $drillTimestamp->toIso8601String(),
                            'max_age_days' => $maxAgeDays,
                        ],
                    ];
                }
            }
        }

        $reportPath = null;
        $hasCriticalItems = collect($items)->contains(fn (array $item) => $item['severity'] === 'critical');
        $hasBackupManifestGap = collect($items)->contains(
            fn (array $item) => ($item['code'] ?? null) === 'restore_drill_backup_manifest_missing'
        );

        if ($persistReport && $enabled && ! $hasCriticalItems && ! $hasBackupManifestGap) {
            File::ensureDirectoryExists($drillPath);

            $report = [
                'status' => 'ok',
                'drilled_at' => now()->toIso8601String(),
                'backup_manifest' => $latestBackup,
                'app_env' => $this->currentEnvironment(),
                'database_connection' => (string) config('database.default'),
                'notes' => [
                    'Non-destructive drill report generated by Laravel runtime.',
                    'Validate backup artifact accessibility outside the application node before using this report as a production sign-off.',
                ],
            ];

            $reportPath = $drillPath.DIRECTORY_SEPARATOR.sprintf(
                'restore-drill-%s.json',
                now()->format('Ymd-His')
            );

            File::put(
                $reportPath,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            $latestDrill = array_merge($report, ['report_path' => $reportPath]);
            $items = array_values(array_filter(
                $items,
                static fn (array $item) => ! in_array((string) ($item['code'] ?? ''), [
                    'restore_drill_missing',
                    'restore_drill_stale',
                    'restore_drill_invalid',
                ], true)
            ));
        }

        return [
            'status' => $this->resolveStatus($items),
            'checked_at' => now()->toIso8601String(),
            'enabled' => $enabled,
            'drill_path' => $drillPath !== '' ? $drillPath : null,
            'latest_drill' => $latestDrill,
            'latest_backup' => $latestBackup,
            'report_path' => $reportPath,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function observabilitySummary(): array
    {
        return [
            'backup' => $this->backupReadinessSummary(),
            'restore_drill' => $this->restoreDrillSummary(persistReport: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('velmix.backup', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathChecks(string $path, string $label, string $message): array
    {
        if ($path === '') {
            return [[
                'severity' => 'critical',
                'code' => 'backup_path_not_configured',
                'message' => sprintf('A required backup path is not configured: %s.', $label),
                'action' => 'Define the required backup path before promoting the release.',
            ]];
        }

        if (! File::isDirectory($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'backup_path_missing',
                'message' => $message,
                'action' => 'Create the path and ensure the application user can write to it before the next backup window.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        if (! is_writable($path)) {
            return [[
                'severity' => 'critical',
                'code' => 'backup_path_not_writable',
                'message' => $message,
                'action' => 'Grant write permissions to the application user before relying on backup or restore workflows.',
                'metric_snapshot' => [
                    'path_label' => $label,
                    'path' => $path,
                ],
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function latestBackupManifest(array $config): ?array
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
                'reason' => 'Manifest payload is not an object.',
            ];
        }

        try {
            Carbon::parse((string) ($data['generated_at'] ?? ''));
        } catch (\Throwable $exception) {
            return [
                'status' => 'invalid',
                'manifest_path' => $manifestPath,
                'reason' => sprintf('Invalid generated_at value: %s', $exception->getMessage()),
            ];
        }

        $data['status'] = 'ok';
        $data['manifest_path'] = $manifestPath;

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRestoreDrillReport(string $drillPath): ?array
    {
        if ($drillPath === '' || ! File::isDirectory($drillPath)) {
            return null;
        }

        $latestFile = collect(File::files($drillPath))
            ->filter(fn (\SplFileInfo $file) => str_starts_with($file->getFilename(), 'restore-drill-'))
            ->sortByDesc(fn (\SplFileInfo $file) => $file->getMTime())
            ->first();

        if (! $latestFile instanceof \SplFileInfo) {
            return null;
        }

        try {
            $data = json_decode((string) File::get($latestFile->getRealPath()), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                'status' => 'invalid',
                'report_path' => $latestFile->getRealPath(),
                'reason' => $exception->getMessage(),
            ];
        }

        if (! is_array($data)) {
            return [
                'status' => 'invalid',
                'report_path' => $latestFile->getRealPath(),
                'reason' => 'Restore drill payload is not an object.',
            ];
        }

        try {
            Carbon::parse((string) ($data['drilled_at'] ?? ''));
        } catch (\Throwable $exception) {
            return [
                'status' => 'invalid',
                'report_path' => $latestFile->getRealPath(),
                'reason' => sprintf('Invalid drilled_at value: %s', $exception->getMessage()),
            ];
        }

        $data['status'] = 'ok';
        $data['report_path'] = $latestFile->getRealPath();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function manifestPath(array $config): string
    {
        $storagePath = $this->normalizePath((string) ($config['storage_path'] ?? ''));
        $manifestFilename = trim((string) ($config['manifest_filename'] ?? 'latest-backup.json'));

        if ($storagePath === '' || $manifestFilename === '') {
            return '';
        }

        return $storagePath.DIRECTORY_SEPARATOR.$manifestFilename;
    }

    private function currentEnvironment(): string
    {
        return (string) config('app.env', app()->environment());
    }

    private function normalizePath(string $path): string
    {
        return rtrim(trim($path), DIRECTORY_SEPARATOR);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function resolveStatus(array $items): string
    {
        if ($items === []) {
            return 'ok';
        }

        if (collect($items)->contains(fn (array $item) => ($item['severity'] ?? null) === 'critical')) {
            return 'critical';
        }

        if (collect($items)->contains(fn (array $item) => ($item['severity'] ?? null) === 'warning')) {
            return 'warning';
        }

        return 'info';
    }
}
