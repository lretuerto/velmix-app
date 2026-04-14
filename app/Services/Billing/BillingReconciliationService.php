<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use App\Services\Billing\Providers\BillingDispatchProviderRegistry;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingReconciliationService
{
    public function __construct(
        private readonly BillingDispatchProviderRegistry $providers,
        private readonly BillingProviderProfileService $profiles,
    ) {
    }

    public function reconcileVoucher(int $tenantId, ?int $userId, int $voucherId, ?string $simulateResult = null): array
    {
        return $this->reconcileDocument($tenantId, $userId, 'electronic_voucher', $voucherId, $simulateResult);
    }

    public function reconcileCreditNote(int $tenantId, ?int $userId, int $creditNoteId, ?string $simulateResult = null): array
    {
        return $this->reconcileDocument($tenantId, $userId, 'sale_credit_note', $creditNoteId, $simulateResult);
    }

    public function reconcilePending(int $tenantId, ?int $userId, int $limit = 20, ?string $simulateResult = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $limit = max(1, min($limit, 100));
        $items = [];

        foreach ([
            ['aggregate_type' => 'electronic_voucher', 'table' => 'electronic_vouchers'],
            ['aggregate_type' => 'sale_credit_note', 'table' => 'sale_credit_notes'],
        ] as $definition) {
            $documents = DB::table($definition['table'])
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'failed'])
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($documents as $aggregateId) {
                $items[] = $definition['aggregate_type'] === 'electronic_voucher'
                    ? $this->reconcileVoucher($tenantId, $userId, $aggregateId, $simulateResult)
                    : $this->reconcileCreditNote($tenantId, $userId, $aggregateId, $simulateResult);

                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'tenant_id' => $tenantId,
            'processed_count' => count($items),
            'items' => $items,
        ];
    }

    private function reconcileDocument(
        int $tenantId,
        ?int $userId,
        string $aggregateType,
        int $aggregateId,
        ?string $simulateResult = null,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        [$documentTable, $summaryLabel] = match ($aggregateType) {
            'electronic_voucher' => ['electronic_vouchers', 'voucher'],
            'sale_credit_note' => ['sale_credit_notes', 'credit note'],
            default => throw new HttpException(422, 'Billing aggregate type is invalid.'),
        };

        return DB::transaction(function () use ($tenantId, $userId, $aggregateType, $aggregateId, $simulateResult, $documentTable, $summaryLabel) {
            $document = DB::table($documentTable)
                ->where('tenant_id', $tenantId)
                ->where('id', $aggregateId)
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                throw new HttpException(404, ucfirst($summaryLabel).' not found.');
            }

            $payloadSnapshot = DB::table('billing_document_payloads')
                ->where('tenant_id', $tenantId)
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->orderByDesc('id')
                ->first();

            if ($payloadSnapshot === null) {
                throw new HttpException(422, 'Billing payload snapshot is required before reconciliation.');
            }

            $event = DB::table('outbox_events')
                ->where('tenant_id', $tenantId)
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $profile = $this->profiles->current($tenantId);
            $effectiveProfile = [
                'provider_code' => $payloadSnapshot->provider_code,
                'environment' => $payloadSnapshot->provider_environment,
                'default_outcome' => $profile['default_outcome'],
                'credentials' => $profile['credentials'],
            ];
            $provider = $this->providers->forCode((string) $payloadSnapshot->provider_code);
            $payload = is_string($payloadSnapshot->payload)
                ? json_decode($payloadSnapshot->payload, true, 512, JSON_THROW_ON_ERROR)
                : json_decode(json_encode($payloadSnapshot->payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $result = $provider->reconcile(
                $document,
                $payload,
                $effectiveProfile,
                [
                    'simulate_result' => $simulateResult,
                    'latest_event_status' => $event?->status,
                ],
            );

            $this->applyDocumentResult($tenantId, $aggregateId, $documentTable, $event, $result, $effectiveProfile);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'billing',
                'billing.reconciliation.executed',
                $aggregateType,
                $aggregateId,
                sprintf('Billing reconciliation executed for %s %d.', $summaryLabel, $aggregateId),
                [
                    'provider_code' => $result['provider_code'],
                    'provider_environment' => $effectiveProfile['environment'],
                    'result_status' => $result['document_status'],
                    'provider_reference' => $result['provider_reference'] ?? null,
                    'sunat_ticket' => $result['ticket'] ?? null,
                ],
            );

            $fresh = DB::table($documentTable)
                ->where('tenant_id', $tenantId)
                ->where('id', $aggregateId)
                ->first(['id', 'status', 'sunat_ticket', 'rejection_reason', 'updated_at']);

            return [
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'provider_code' => $result['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'status' => $result['status'],
                'document_status' => $result['document_status'],
                'provider_reference' => $result['provider_reference'] ?? null,
                'sunat_ticket' => $result['ticket'] ?? null,
                'message' => $result['message'] ?? null,
                'document' => [
                    'id' => (int) $fresh->id,
                    'status' => (string) $fresh->status,
                    'sunat_ticket' => $fresh->sunat_ticket,
                    'rejection_reason' => $fresh->rejection_reason,
                    'updated_at' => (string) $fresh->updated_at,
                ],
            ];
        });
    }

    private function applyDocumentResult(
        int $tenantId,
        int $aggregateId,
        string $documentTable,
        ?object $event,
        array $result,
        array $effectiveProfile,
    ): void {
        $documentStatus = (string) ($result['document_status'] ?? 'pending');

        DB::table($documentTable)
            ->where('tenant_id', $tenantId)
            ->where('id', $aggregateId)
            ->update([
                'status' => $documentStatus,
                'sunat_ticket' => $result['ticket'] ?? null,
                'rejection_reason' => $documentStatus === 'rejected'
                    ? ($result['message'] ?? 'Rejected by reconciliation.')
                    : null,
                'updated_at' => now(),
            ]);

        if ($event !== null) {
            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'status' => match ($documentStatus) {
                        'accepted' => 'processed',
                        'rejected' => 'rejected',
                        default => 'pending',
                    },
                    'last_error' => $documentStatus === 'rejected' ? ($result['message'] ?? null) : null,
                    'updated_at' => now(),
                ]);

            DB::table('outbox_attempts')->insert([
                'outbox_event_id' => $event->id,
                'status' => $documentStatus,
                'provider_code' => $result['provider_code'],
                'provider_environment' => $effectiveProfile['environment'],
                'provider_reference' => $result['provider_reference'] ?? null,
                'sunat_ticket' => $result['ticket'] ?? null,
                'error_message' => $documentStatus === 'accepted' ? null : ($result['message'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
