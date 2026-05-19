<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsControlTowerSnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'snapshot_date',
        'label',
        'overall_status',
        'critical_gate_count',
        'warning_gate_count',
        'sales_completed_total',
        'collections_total',
        'cash_discrepancy_total',
        'billing_pending_backlog_count',
        'billing_failed_backlog_count',
        'finance_overdue_total',
        'finance_broken_promise_count',
        'operations_open_alert_count',
        'operations_critical_alert_count',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
