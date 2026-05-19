<?php

namespace App\Services\Billing\Providers;

use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingDispatchProviderRegistry
{
    public function forCode(string $providerCode): BillingDispatchProvider
    {
        return match ($providerCode) {
            'fake_sunat' => app(FakeSunatDispatchProvider::class),
            default => throw new HttpException(422, 'Billing provider is invalid.'),
        };
    }
}
