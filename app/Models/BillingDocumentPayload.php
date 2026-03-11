<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingDocumentPayload extends Model
{
    protected $fillable = [
        'tenant_id',
        'aggregate_type',
        'aggregate_id',
        'provider_code',
        'provider_environment',
        'schema_version',
        'document_kind',
        'document_number',
        'payload_hash',
        'payload',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
