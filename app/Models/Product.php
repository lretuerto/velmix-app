<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'tenant_id',
        'sku',
        'name',
        'status',
        'is_controlled',
    ];

    protected function casts(): array
    {
        return [
            'is_controlled' => 'bool',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }
}
