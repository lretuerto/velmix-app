<?php

namespace App\Services\Billing;

use App\Models\BillingDocumentPayload;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingDocumentPayloadService
{
    public function createForVoucher(int $tenantId, int $voucherId, ?int $userId = null): array
    {
        $voucher = DB::table('electronic_vouchers')
            ->join('sales', 'sales.id', '=', 'electronic_vouchers.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('tenants', 'tenants.id', '=', 'electronic_vouchers.tenant_id')
            ->where('electronic_vouchers.tenant_id', $tenantId)
            ->where('electronic_vouchers.id', $voucherId)
            ->first([
                'electronic_vouchers.id',
                'electronic_vouchers.tenant_id',
                'electronic_vouchers.sale_id',
                'electronic_vouchers.type',
                'electronic_vouchers.series',
                'electronic_vouchers.number',
                'electronic_vouchers.status',
                'electronic_vouchers.created_at',
                'sales.reference as sale_reference',
                'sales.payment_method',
                'sales.total_amount',
                'sales.gross_cost',
                'sales.gross_margin',
                'customers.document_type as customer_document_type',
                'customers.document_number as customer_document_number',
                'customers.name as customer_name',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
            ]);

        if ($voucher === null) {
            throw new HttpException(404, 'Voucher not found for payload.');
        }

        $items = DB::table('sale_items')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('lots', 'lots.id', '=', 'sale_items.lot_id')
            ->where('sale_items.sale_id', $voucher->sale_id)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.id',
                'sale_items.quantity',
                'sale_items.unit_price',
                'sale_items.line_total',
                'products.sku as product_sku',
                'products.name as product_name',
                'lots.code as lot_code',
            ])
            ->values()
            ->map(fn (object $item, int $index) => [
                'line' => $index + 1,
                'sale_item_id' => (int) $item->id,
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
                'lot_code' => $item->lot_code,
                'quantity' => (int) $item->quantity,
                'unit_price' => round((float) $item->unit_price, 2),
                'line_total' => round((float) $item->line_total, 2),
            ])
            ->all();

        return $this->persistSnapshot(
            $tenantId,
            'electronic_voucher',
            $voucherId,
            $userId,
            'voucher',
            sprintf('%s-%d', $voucher->series, $voucher->number),
            [
                'issuer' => [
                    'tenant_id' => (int) $voucher->tenant_id,
                    'code' => (string) $voucher->tenant_code,
                    'name' => (string) $voucher->tenant_name,
                ],
                'document' => [
                    'id' => (int) $voucher->id,
                    'type' => (string) $voucher->type,
                    'series' => (string) $voucher->series,
                    'number' => (int) $voucher->number,
                    'status' => (string) $voucher->status,
                    'issued_at' => (string) $voucher->created_at,
                ],
                'sale' => [
                    'id' => (int) $voucher->sale_id,
                    'reference' => (string) $voucher->sale_reference,
                    'payment_method' => $voucher->payment_method,
                    'total_amount' => round((float) $voucher->total_amount, 2),
                    'gross_cost' => round((float) ($voucher->gross_cost ?? 0), 2),
                    'gross_margin' => round((float) ($voucher->gross_margin ?? 0), 2),
                ],
                'customer' => $voucher->customer_name !== null ? [
                    'document_type' => $voucher->customer_document_type,
                    'document_number' => $voucher->customer_document_number,
                    'name' => $voucher->customer_name,
                ] : null,
                'items' => $items,
                'totals' => [
                    'total_amount' => round((float) $voucher->total_amount, 2),
                ],
            ],
        );
    }

    public function createForCreditNote(int $tenantId, int $creditNoteId, ?int $userId = null): array
    {
        $creditNote = DB::table('sale_credit_notes')
            ->join('sales', 'sales.id', '=', 'sale_credit_notes.sale_id')
            ->join('electronic_vouchers', 'electronic_vouchers.id', '=', 'sale_credit_notes.electronic_voucher_id')
            ->join('tenants', 'tenants.id', '=', 'sale_credit_notes.tenant_id')
            ->where('sale_credit_notes.tenant_id', $tenantId)
            ->where('sale_credit_notes.id', $creditNoteId)
            ->first([
                'sale_credit_notes.id',
                'sale_credit_notes.tenant_id',
                'sale_credit_notes.sale_id',
                'sale_credit_notes.electronic_voucher_id',
                'sale_credit_notes.series',
                'sale_credit_notes.number',
                'sale_credit_notes.status',
                'sale_credit_notes.reason',
                'sale_credit_notes.total_amount',
                'sale_credit_notes.refunded_amount',
                'sale_credit_notes.refund_payment_method',
                'sale_credit_notes.created_at',
                'sales.reference as sale_reference',
                'electronic_vouchers.type as voucher_type',
                'electronic_vouchers.series as voucher_series',
                'electronic_vouchers.number as voucher_number',
                'tenants.code as tenant_code',
                'tenants.name as tenant_name',
            ]);

        if ($creditNote === null) {
            throw new HttpException(404, 'Credit note not found for payload.');
        }

        $items = DB::table('sale_credit_note_items')
            ->leftJoin('products', 'products.id', '=', 'sale_credit_note_items.product_id')
            ->leftJoin('lots', 'lots.id', '=', 'sale_credit_note_items.lot_id')
            ->where('sale_credit_note_items.sale_credit_note_id', $creditNoteId)
            ->orderBy('sale_credit_note_items.id')
            ->get([
                'sale_credit_note_items.sale_item_id',
                'sale_credit_note_items.quantity',
                'sale_credit_note_items.unit_price',
                'sale_credit_note_items.line_total',
                'products.sku as product_sku',
                'products.name as product_name',
                'lots.code as lot_code',
            ])
            ->values()
            ->map(fn (object $item, int $index) => [
                'line' => $index + 1,
                'sale_item_id' => (int) $item->sale_item_id,
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
                'lot_code' => $item->lot_code,
                'quantity' => (int) $item->quantity,
                'unit_price' => round((float) $item->unit_price, 2),
                'line_total' => round((float) $item->line_total, 2),
            ])
            ->all();

        return $this->persistSnapshot(
            $tenantId,
            'sale_credit_note',
            $creditNoteId,
            $userId,
            'credit_note',
            sprintf('%s-%d', $creditNote->series, $creditNote->number),
            [
                'issuer' => [
                    'tenant_id' => (int) $creditNote->tenant_id,
                    'code' => (string) $creditNote->tenant_code,
                    'name' => (string) $creditNote->tenant_name,
                ],
                'document' => [
                    'id' => (int) $creditNote->id,
                    'series' => (string) $creditNote->series,
                    'number' => (int) $creditNote->number,
                    'status' => (string) $creditNote->status,
                    'reason' => (string) $creditNote->reason,
                    'issued_at' => (string) $creditNote->created_at,
                ],
                'reference_document' => [
                    'voucher_id' => (int) $creditNote->electronic_voucher_id,
                    'type' => (string) $creditNote->voucher_type,
                    'series' => (string) $creditNote->voucher_series,
                    'number' => (int) $creditNote->voucher_number,
                ],
                'sale' => [
                    'id' => (int) $creditNote->sale_id,
                    'reference' => (string) $creditNote->sale_reference,
                ],
                'items' => $items,
                'totals' => [
                    'total_amount' => round((float) $creditNote->total_amount, 2),
                    'refunded_amount' => round((float) $creditNote->refunded_amount, 2),
                    'refund_payment_method' => $creditNote->refund_payment_method,
                ],
            ],
        );
    }

    public function listForAggregate(int $tenantId, string $aggregateType, int $aggregateId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return BillingDocumentPayload::query()
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)
            ->orderBy('id')
            ->get()
            ->map(fn (BillingDocumentPayload $payload) => [
                'id' => $payload->id,
                'aggregate_type' => $payload->aggregate_type,
                'aggregate_id' => $payload->aggregate_id,
                'provider_code' => $payload->provider_code,
                'provider_environment' => $payload->provider_environment,
                'schema_version' => $payload->schema_version,
                'document_kind' => $payload->document_kind,
                'document_number' => $payload->document_number,
                'payload_hash' => $payload->payload_hash,
                'payload' => $payload->payload,
                'created_by_user_id' => $payload->created_by_user_id,
                'created_at' => $payload->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function outboxEnvelope(array $snapshot): array
    {
        return [
            'billing_payload_id' => $snapshot['id'],
            'provider_code' => $snapshot['provider_code'],
            'provider_environment' => $snapshot['provider_environment'],
            'schema_version' => $snapshot['schema_version'],
            'document_kind' => $snapshot['document_kind'],
            'document_number' => $snapshot['document_number'],
            'document_payload' => $snapshot['payload'],
        ];
    }

    private function persistSnapshot(
        int $tenantId,
        string $aggregateType,
        int $aggregateId,
        ?int $userId,
        string $documentKind,
        string $documentNumber,
        array $documentPayload,
    ): array {
        $profile = app(BillingProviderProfileService::class)->current($tenantId);
        $schemaVersion = sprintf('%s.v1', $profile['provider_code']);
        $payloadJson = json_encode($documentPayload, JSON_THROW_ON_ERROR);

        $snapshot = BillingDocumentPayload::query()->create([
            'tenant_id' => $tenantId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'provider_code' => (string) $profile['provider_code'],
            'provider_environment' => (string) $profile['environment'],
            'schema_version' => $schemaVersion,
            'document_kind' => $documentKind,
            'document_number' => $documentNumber,
            'payload_hash' => hash('sha256', $payloadJson),
            'payload' => $documentPayload,
            'created_by_user_id' => $userId,
        ]);

        return [
            'id' => $snapshot->id,
            'provider_code' => $snapshot->provider_code,
            'provider_environment' => $snapshot->provider_environment,
            'schema_version' => $snapshot->schema_version,
            'document_kind' => $snapshot->document_kind,
            'document_number' => $snapshot->document_number,
            'payload_hash' => $snapshot->payload_hash,
            'payload' => $snapshot->payload,
        ];
    }
}
