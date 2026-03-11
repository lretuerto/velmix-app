<?php

namespace App\Services\Billing;

use App\Models\BillingProviderProfile;
use App\Services\Audit\TenantActivityLogService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingProviderProfileService
{
    public function current(int $tenantId): array
    {
        return $this->serialize($this->ensureModel($tenantId));
    }

    public function update(int $tenantId, int $userId, array $attributes): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $profile = $this->ensureModel($tenantId);
        $providerCode = $attributes['provider_code'] ?? $profile->provider_code;
        $environment = $attributes['environment'] ?? $profile->environment;
        $credentials = array_key_exists('credentials', $attributes) ? $attributes['credentials'] : $profile->credentials;
        $healthBecameStale = $providerCode !== $profile->provider_code
            || $environment !== $profile->environment
            || $credentials !== $profile->credentials;

        $profile->fill([
            'provider_code' => $providerCode,
            'environment' => $environment,
            'default_outcome' => $attributes['default_outcome'] ?? $profile->default_outcome,
            'credentials' => $credentials,
        ]);

        if ($healthBecameStale) {
            $profile->fill([
                'health_status' => 'unknown',
                'health_checked_at' => null,
                'health_message' => null,
            ]);
        }

        $profile->save();

        app(TenantActivityLogService::class)->record(
            $tenantId,
            $userId,
            'billing',
            'billing.provider_profile.updated',
            'billing_provider_profile',
            $profile->id,
            sprintf('Billing provider profile updated for %s.', $profile->provider_code),
            [
                'provider_code' => $profile->provider_code,
                'environment' => $profile->environment,
                'default_outcome' => $profile->default_outcome,
                'health_status' => $profile->health_status,
            ],
        );

        return $this->serialize($profile->fresh());
    }

    public function ensureModel(int $tenantId): BillingProviderProfile
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return BillingProviderProfile::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'provider_code' => 'fake_sunat',
                'environment' => 'sandbox',
                'default_outcome' => 'accepted',
                'credentials' => null,
                'health_status' => 'unknown',
                'health_checked_at' => null,
                'health_message' => null,
            ],
        );
    }

    private function serialize(BillingProviderProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'tenant_id' => $profile->tenant_id,
            'provider_code' => $profile->provider_code,
            'environment' => $profile->environment,
            'default_outcome' => $profile->default_outcome,
            'credentials' => $profile->credentials,
            'health_status' => $profile->health_status,
            'health_checked_at' => $profile->health_checked_at?->toIso8601String(),
            'health_message' => $profile->health_message,
        ];
    }
}
