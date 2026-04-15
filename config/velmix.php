<?php

return [
    'alerts' => [
        'billing_days' => env('VELMIX_ALERT_BILLING_DAYS', 7),
        'finance_days_ahead' => env('VELMIX_ALERT_FINANCE_DAYS_AHEAD', 7),
        'priority_limit' => env('VELMIX_ALERT_PRIORITY_LIMIT', 5),
        'failure_limit' => env('VELMIX_ALERT_FAILURE_LIMIT', 5),
        'stale_follow_up_days' => env('VELMIX_ALERT_STALE_FOLLOW_UP_DAYS', 3),
    ],
    'retention' => [
        'idempotency_days' => env('VELMIX_RETENTION_IDEMPOTENCY_DAYS', 14),
        'team_invitations_days' => env('VELMIX_RETENTION_TEAM_INVITATIONS_DAYS', 90),
        'control_tower_snapshots_days' => env('VELMIX_RETENTION_CONTROL_TOWER_SNAPSHOTS_DAYS', 90),
    ],
];
