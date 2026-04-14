<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'tenant_id',
        'method',
        'path',
        'idempotency_key',
        'request_hash',
        'status',
        'locked_until',
        'response_status',
        'response_headers',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'locked_until' => 'datetime',
            'response_headers' => 'array',
        ];
    }
}
