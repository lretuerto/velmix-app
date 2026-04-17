<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Throwable;

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
            'health_checked_at' => 'datetime',
        ];
    }

    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): ?array {
                if ($value === null || $value === '') {
                    return null;
                }

                if (is_array($value)) {
                    return $value;
                }

                $stringValue = (string) $value;
                $decoded = json_decode($stringValue, true);

                if (
                    is_array($decoded)
                    && isset($decoded['payload'])
                    && is_string($decoded['payload'])
                ) {
                    try {
                        return json_decode(
                            Crypt::decryptString($decoded['payload']),
                            true,
                            512,
                            JSON_THROW_ON_ERROR,
                        );
                    } catch (Throwable) {
                        return null;
                    }
                }

                try {
                    return json_decode(
                        Crypt::decryptString($stringValue),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (Throwable) {
                    return is_array($decoded) ? $decoded : null;
                }
            },
            set: function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                return json_encode([
                    'payload' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)),
                ], JSON_THROW_ON_ERROR);
            },
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
