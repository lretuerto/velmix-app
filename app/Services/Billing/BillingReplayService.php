<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingReplayService
{
    public function replayVoucher(int $tenantId, int $userId, int $voucherId): array
    {
        return $this->replayAggregate($tenantId, $userId, 'electronic_voucher', $voucherId);
    }

    public function replayCreditNote(int $tenantId, int $userId, int $creditNoteId): array
    {
        return $this->replayAggregate($tenantId, $userId, 'sale_credit_note', $creditNoteId);
    }

    private function replayAggregate(int $tenantId, int $userId, string $aggregateType, int $aggregateId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $aggregateType, $aggregateId) {
            $context = $this->contextForAggregate($tenantId, $aggregateType, $aggregateId);
            $pendingExists = DB::table('outbox_events')
                ->where('tenant_id', $tenantId)
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->where('status', 'pending')
                ->exists();

            if ($pendingExists) {
                throw new HttpException(422, 'Document already has a pending outbox event.');
            }

            if (($context['document_status'] ?? null) === 'accepted') {
                throw new HttpException(422, 'Accepted billing documents cannot be replayed.');
            }

            $snapshot = $this->latestOrCreateSnapshot($tenantId, $aggregateType, $aggregateId, $userId);

            DB::table($context['document_table'])
                ->where('id', $aggregateId)
                ->update([
                    'status' => 'pending',
                    'sunat_ticket' => null,
                    'rejection_reason' => null,
                    'updated_at' => now(),
                ]);

            $eventId = DB::table('outbox_events')->insertGetId([
                'tenant_id' => $tenantId,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $context['event_type'],
                'payload' => json_encode(array_merge(
                    $context['payload_base'],
                    app(BillingDocumentPayloadService::class)->outboxEnvelope($snapshot),
                ), JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'retry_count' => 0,
                'last_error' => null,
                'replayed_from_event_id' => $context['latest_event_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'billing',
                'billing.outbox.replayed',
                'outbox_event',
                $eventId,
                sprintf('Billing document %s replayed as outbox event %d.', $context['document_number'], $eventId),
                [
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'replayed_from_event_id' => $context['latest_event_id'],
                    'billing_payload_id' => $snapshot['id'],
                    'schema_version' => $snapshot['schema_version'],
                ],
            );

            return [
                'event_id' => $eventId,
                'aggregate_type' => $aggregateType,
                'document_id' => $aggregateId,
                'document_number' => $context['document_number'],
                'status' => 'pending',
                'replayed_from_event_id' => $context['latest_event_id'],
                'billing_payload_id' => $snapshot['id'],
                'schema_version' => $snapshot['schema_version'],
            ];
        });
    }

    private function latestOrCreateSnapshot(int $tenantId, string $aggregateType, int $aggregateId, int $userId): array
    {
        $latest = DB::table('billing_document_payloads')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->orderByDesc('id')
            ->first(['id', 'provider_code', 'provider_environment', 'schema_version', 'document_kind', 'document_number', 'payload_hash', 'payload']);

        if ($latest !== null) {
            return [
                'id' => (int) $latest->id,
                'provider_code' => (string) $latest->provider_code,
                'provider_environment' => (string) $latest->provider_environment,
                'schema_version' => (string) $latest->schema_version,
                'document_kind' => (string) $latest->document_kind,
                'document_number' => (string) $latest->document_number,
                'payload_hash' => (string) $latest->payload_hash,
                'payload' => json_decode((string) $latest->payload, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $aggregateType === 'electronic_voucher'
            ? app(BillingDocumentPayloadService::class)->createForVoucher($tenantId, $aggregateId, $userId)
            : app(BillingDocumentPayloadService::class)->createForCreditNote($tenantId, $aggregateId, $userId);
    }

    private function contextForAggregate(int $tenantId, string $aggregateType, int $aggregateId): array
    {
        return $aggregateType === 'electronic_voucher'
            ? $this->voucherContext($tenantId, $aggregateId)
            : $this->creditNoteContext($tenantId, $aggregateId);
    }

    private function voucherContext(int $tenantId, int $voucherId): array
    {
        $voucher = DB::table('electronic_vouchers')
            ->join('sales', 'sales.id', '=', 'electronic_vouchers.sale_id')
            ->where('electronic_vouchers.tenant_id', $tenantId)
            ->where('electronic_vouchers.id', $voucherId)
            ->first([
                'electronic_vouchers.id',
                'electronic_vouchers.sale_id',
                'electronic_vouchers.series',
                'electronic_vouchers.number',
                'electronic_vouchers.status',
                'sales.reference as sale_reference',
                'sales.total_amount',
            ]);

        if ($voucher === null) {
            throw new HttpException(404, 'Voucher not found.');
        }

        $latestEventId = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'electronic_voucher')
            ->where('aggregate_id', $voucherId)
            ->max('id');

        return [
            'document_table' => 'electronic_vouchers',
            'document_status' => (string) $voucher->status,
            'event_type' => 'voucher.created',
            'document_number' => sprintf('%s-%d', $voucher->series, $voucher->number),
            'latest_event_id' => $latestEventId !== null ? (int) $latestEventId : null,
            'payload_base' => [
                'voucher_id' => (int) $voucher->id,
                'sale_id' => (int) $voucher->sale_id,
                'sale_reference' => (string) $voucher->sale_reference,
                'series' => (string) $voucher->series,
                'number' => (int) $voucher->number,
                'total_amount' => (float) $voucher->total_amount,
            ],
        ];
    }

    private function creditNoteContext(int $tenantId, int $creditNoteId): array
    {
        $creditNote = DB::table('sale_credit_notes')
            ->where('tenant_id', $tenantId)
            ->where('id', $creditNoteId)
            ->first(['id', 'sale_id', 'series', 'number', 'status']);

        if ($creditNote === null) {
            throw new HttpException(404, 'Credit note not found.');
        }

        $latestEventId = DB::table('outbox_events')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'sale_credit_note')
            ->where('aggregate_id', $creditNoteId)
            ->max('id');

        return [
            'document_table' => 'sale_credit_notes',
            'document_status' => (string) $creditNote->status,
            'event_type' => 'credit_note.created',
            'document_number' => sprintf('%s-%d', $creditNote->series, $creditNote->number),
            'latest_event_id' => $latestEventId !== null ? (int) $latestEventId : null,
            'payload_base' => [
                'credit_note_id' => (int) $creditNote->id,
                'sale_id' => (int) $creditNote->sale_id,
                'series' => (string) $creditNote->series,
                'number' => (int) $creditNote->number,
            ],
        ];
    }
}
