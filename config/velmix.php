<?php

$alertNotificationChannels = array_values(array_filter(array_map(
    static fn ($channel) => trim((string) $channel),
    explode(',', (string) env('VELMIX_ALERT_NOTIFY_CHANNELS', 'log'))
)));

$sharedPath = trim((string) env('VELMIX_SHARED_PATH', ''));
$defaultBackupStoragePath = $sharedPath !== ''
    ? rtrim($sharedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'backups'
    : storage_path('app/backups');
$defaultRestoreDrillPath = $sharedPath !== ''
    ? rtrim($sharedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'restore-drills'
    : storage_path('app/restore-drills');
$defaultStagingCertificationPath = $sharedPath !== ''
    ? rtrim($sharedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'staging-certifications'
    : storage_path('app/staging-certifications');
$defaultReleasePromotionPath = $sharedPath !== ''
    ? rtrim($sharedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'release-promotions'
    : storage_path('app/release-promotions');
$defaultReleaseCutoverPath = $sharedPath !== ''
    ? rtrim($sharedPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'release-cutovers'
    : storage_path('app/release-cutovers');
$stagingCertificationRequiredEnvironments = array_values(array_filter(array_map(
    static fn ($environment) => trim((string) $environment),
    explode(',', (string) env('VELMIX_STAGING_CERTIFICATION_REQUIRED_ENVS', 'staging,production'))
)));
$releasePromotionRequiredEnvironments = array_values(array_filter(array_map(
    static fn ($environment) => trim((string) $environment),
    explode(',', (string) env('VELMIX_RELEASE_PROMOTION_REQUIRED_ENVS', 'staging'))
)));
$releaseCutoverRequiredEnvironments = array_values(array_filter(array_map(
    static fn ($environment) => trim((string) $environment),
    explode(',', (string) env('VELMIX_RELEASE_CUTOVER_REQUIRED_ENVS', 'production'))
)));

return [
    'alerts' => [
        'billing_days' => env('VELMIX_ALERT_BILLING_DAYS', 7),
        'finance_days_ahead' => env('VELMIX_ALERT_FINANCE_DAYS_AHEAD', 7),
        'priority_limit' => env('VELMIX_ALERT_PRIORITY_LIMIT', 5),
        'failure_limit' => env('VELMIX_ALERT_FAILURE_LIMIT', 5),
        'stale_follow_up_days' => env('VELMIX_ALERT_STALE_FOLLOW_UP_DAYS', 3),
        'notifications' => [
            'channels' => $alertNotificationChannels,
            'minimum_severity' => env('VELMIX_ALERT_NOTIFY_MIN_SEVERITY', 'warning'),
            'cooldown_minutes' => env('VELMIX_ALERT_NOTIFY_COOLDOWN_MINUTES', 30),
            'log_channel' => env('VELMIX_ALERT_NOTIFY_LOG_CHANNEL', 'daily_json'),
            'webhook_url' => env('VELMIX_ALERT_WEBHOOK_URL'),
            'webhook_timeout_seconds' => env('VELMIX_ALERT_WEBHOOK_TIMEOUT_SECONDS', 5),
            'slack_webhook_url' => env('VELMIX_ALERT_SLACK_WEBHOOK_URL'),
            'slack_channel' => env('VELMIX_ALERT_SLACK_CHANNEL'),
            'slack_username' => env('VELMIX_ALERT_SLACK_USERNAME', 'VELMiX Alerts'),
            'slack_icon_emoji' => env('VELMIX_ALERT_SLACK_ICON_EMOJI', ':rotating_light:'),
        ],
    ],
    'scheduler' => [
        'timezone' => env('VELMIX_SCHEDULER_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
        'on_one_server' => env('VELMIX_SCHEDULER_ON_ONE_SERVER', false),
        'dispatch_limit' => env('VELMIX_SCHEDULER_DISPATCH_LIMIT', 20),
        'dispatch_every_minutes' => env('VELMIX_SCHEDULER_DISPATCH_EVERY_MINUTES', 1),
        'dispatch_overlap_minutes' => env('VELMIX_SCHEDULER_DISPATCH_OVERLAP_MINUTES', 10),
        'reconcile_limit' => env('VELMIX_SCHEDULER_RECONCILE_LIMIT', 20),
        'reconcile_every_minutes' => env('VELMIX_SCHEDULER_RECONCILE_EVERY_MINUTES', 5),
        'reconcile_overlap_minutes' => env('VELMIX_SCHEDULER_RECONCILE_OVERLAP_MINUTES', 15),
        'alerts_every_minutes' => env('VELMIX_SCHEDULER_ALERTS_EVERY_MINUTES', 5),
        'alerts_overlap_minutes' => env('VELMIX_SCHEDULER_ALERTS_OVERLAP_MINUTES', 10),
        'alert_dispatch_every_minutes' => env('VELMIX_SCHEDULER_ALERT_DISPATCH_EVERY_MINUTES', 5),
        'alert_dispatch_overlap_minutes' => env('VELMIX_SCHEDULER_ALERT_DISPATCH_OVERLAP_MINUTES', 10),
        'prune_at' => env('VELMIX_SCHEDULER_PRUNE_AT', '03:15'),
        'prune_overlap_minutes' => env('VELMIX_SCHEDULER_PRUNE_OVERLAP_MINUTES', 180),
    ],
    'retention' => [
        'idempotency_days' => env('VELMIX_RETENTION_IDEMPOTENCY_DAYS', 14),
        'outbox_attempts_days' => env('VELMIX_RETENTION_OUTBOX_ATTEMPTS_DAYS', 180),
        'team_invitations_days' => env('VELMIX_RETENTION_TEAM_INVITATIONS_DAYS', 90),
        'control_tower_snapshots_days' => env('VELMIX_RETENTION_CONTROL_TOWER_SNAPSHOTS_DAYS', 90),
    ],
    'backup' => [
        'enabled' => env('VELMIX_BACKUP_ENABLED', false),
        'driver' => env('VELMIX_BACKUP_DRIVER', 'external'),
        'storage_path' => env('VELMIX_BACKUP_STORAGE_PATH', $defaultBackupStoragePath),
        'manifest_filename' => env('VELMIX_BACKUP_MANIFEST_FILENAME', 'latest-backup.json'),
        'history_path' => env('VELMIX_BACKUP_HISTORY_PATH', $defaultBackupStoragePath.DIRECTORY_SEPARATOR.'history'),
        'freshness_hours' => env('VELMIX_BACKUP_MAX_AGE_HOURS', 26),
        'retention_days' => env('VELMIX_BACKUP_RETENTION_DAYS', 14),
        'require_encryption' => env('VELMIX_BACKUP_REQUIRE_ENCRYPTION', true),
        'encryption_passphrase' => env('VELMIX_BACKUP_ENCRYPTION_PASSPHRASE'),
        'restore_drill_path' => env('VELMIX_RESTORE_DRILL_PATH', $defaultRestoreDrillPath),
        'restore_drill_max_age_days' => env('VELMIX_RESTORE_DRILL_MAX_AGE_DAYS', 30),
    ],
    'staging_certification' => [
        'expected_environment' => env('VELMIX_STAGING_CERTIFICATION_ENV', 'staging'),
        'required_environments' => $stagingCertificationRequiredEnvironments,
        'storage_path' => env('VELMIX_STAGING_CERTIFICATION_STORAGE_PATH', $defaultStagingCertificationPath),
        'manifest_filename' => env('VELMIX_STAGING_CERTIFICATION_MANIFEST_FILENAME', 'latest-staging-certification.json'),
        'history_path' => env('VELMIX_STAGING_CERTIFICATION_HISTORY_PATH', $defaultStagingCertificationPath.DIRECTORY_SEPARATOR.'history'),
        'freshness_hours' => env('VELMIX_STAGING_CERTIFICATION_MAX_AGE_HOURS', 168),
        'release_identifier' => env('VELMIX_RELEASE_IDENTIFIER'),
    ],
    'release_promotion' => [
        'expected_environment' => env('VELMIX_RELEASE_PROMOTION_ENV', env('VELMIX_STAGING_CERTIFICATION_ENV', 'staging')),
        'required_environments' => $releasePromotionRequiredEnvironments,
        'storage_path' => env('VELMIX_RELEASE_PROMOTION_STORAGE_PATH', $defaultReleasePromotionPath),
        'manifest_filename' => env('VELMIX_RELEASE_PROMOTION_MANIFEST_FILENAME', 'latest-release-promotion.json'),
        'history_path' => env('VELMIX_RELEASE_PROMOTION_HISTORY_PATH', $defaultReleasePromotionPath.DIRECTORY_SEPARATOR.'history'),
        'freshness_hours' => env('VELMIX_RELEASE_PROMOTION_MAX_AGE_HOURS', 72),
        'release_identifier' => env('VELMIX_RELEASE_IDENTIFIER'),
    ],
    'release_cutover' => [
        'expected_environment' => env('VELMIX_RELEASE_CUTOVER_ENV', 'production'),
        'required_environments' => $releaseCutoverRequiredEnvironments,
        'storage_path' => env('VELMIX_RELEASE_CUTOVER_STORAGE_PATH', $defaultReleaseCutoverPath),
        'manifest_filename' => env('VELMIX_RELEASE_CUTOVER_MANIFEST_FILENAME', 'latest-release-cutover.json'),
        'history_path' => env('VELMIX_RELEASE_CUTOVER_HISTORY_PATH', $defaultReleaseCutoverPath.DIRECTORY_SEPARATOR.'history'),
        'freshness_hours' => env('VELMIX_RELEASE_CUTOVER_MAX_AGE_HOURS', 24),
        'release_identifier' => env('VELMIX_RELEASE_IDENTIFIER'),
    ],
];
