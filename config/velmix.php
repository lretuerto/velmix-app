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
];
