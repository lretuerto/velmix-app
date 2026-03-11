<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxAttempt extends Model
{
    protected $fillable = [
        'outbox_event_id',
        'status',
        'sunat_ticket',
        'error_message',
    ];

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class);
    }
}
