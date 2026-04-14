<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingProviderProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'provider_code',
        'environment',
        'default_outcome',
        'credentials',
        'health_status',
        'health_checked_at',
        'health_message',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'health_checked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
