<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicVoucher extends Model
{
    protected $fillable = [
        'tenant_id',
        'sale_id',
        'type',
        'series',
        'number',
        'status',
        'sunat_ticket',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
