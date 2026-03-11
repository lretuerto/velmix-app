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
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
