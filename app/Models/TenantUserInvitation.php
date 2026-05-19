<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUserInvitation extends Model
{
    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'invited_by_user_id',
        'accepted_by_user_id',
        'status',
        'pending_guard',
        'token_hash',
        'role_codes',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'role_codes' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
