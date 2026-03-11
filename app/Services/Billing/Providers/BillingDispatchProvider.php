<?php

namespace App\Services\Billing\Providers;

interface BillingDispatchProvider
{
    public function code(): string;

    public function dispatch(object $event, array $payload, array $profile, array $options = []): array;
}
