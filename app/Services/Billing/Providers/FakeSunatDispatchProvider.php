<?php

namespace App\Services\Billing\Providers;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FakeSunatDispatchProvider implements BillingDispatchProvider
{
    public function code(): string
    {
        return 'fake_sunat';
    }

    public function checkHealth(array $profile): array
    {
        $environment = (string) ($profile['environment'] ?? 'sandbox');

        return [
            'status' => 'healthy',
            'message' => sprintf('Provider %s is reachable in %s mode.', $this->code(), $environment),
            'capabilities' => [
                'voucher_dispatch' => true,
                'credit_note_dispatch' => true,
                'simulated' => true,
            ],
        ];
    }

    public function dispatch(object $event, array $payload, array $profile, array $options = []): array
    {
        $outcome = (string) ($options['simulate_result'] ?? $profile['default_outcome'] ?? 'accepted');

        if (! in_array($outcome, ['accepted', 'rejected', 'transient_fail'], true)) {
            throw new HttpException(422, 'Dispatch outcome is invalid.');
        }

        if ($outcome === 'transient_fail') {
            return [
                'provider_code' => $this->code(),
                'provider_reference' => null,
                'status' => 'failed',
                'document_status' => 'failed',
                'ticket' => null,
                'message' => 'Temporary transport failure.',
            ];
        }

        if ($outcome === 'rejected') {
            return [
                'provider_code' => $this->code(),
                'provider_reference' => null,
                'status' => 'rejected',
                'document_status' => 'rejected',
                'ticket' => null,
                'message' => 'Rejected by SUNAT validation.',
            ];
        }

        $ticket = 'SUNAT-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'provider_code' => $this->code(),
            'provider_reference' => $ticket,
            'status' => 'processed',
            'document_status' => 'accepted',
            'ticket' => $ticket,
            'message' => null,
        ];
    }
}
