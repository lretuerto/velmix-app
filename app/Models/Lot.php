<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lot extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_id',
        'code',
        'expires_at',
        'stock_quantity',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'stock_quantity' => 'int',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
