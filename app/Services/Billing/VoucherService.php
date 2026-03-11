<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoucherService
{
    public function createFromSale(int $tenantId, int $saleId, string $type, ?int $userId = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (! in_array($type, ['boleta', 'factura'], true)) {
            throw new HttpException(422, 'Voucher type is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $saleId, $type, $userId) {
            $sale = DB::table('sales')
                ->where('id', $saleId)
                ->where('tenant_id', $tenantId)
                ->first(['id', 'reference', 'total_amount']);

            if ($sale === null) {
                throw new HttpException(404, 'Sale not found.');
            }

            $existingVoucher = DB::table('electronic_vouchers')
                ->where('sale_id', $saleId)
                ->first(['id', 'series', 'number', 'status']);

            if ($existingVoucher !== null) {
                throw new HttpException(422, 'Sale already has a voucher.');
            }

            $series = $type === 'boleta' ? 'B001' : 'F001';
            $nextNumber = ((int) DB::table('electronic_vouchers')
                ->where('tenant_id', $tenantId)
                ->where('series', $series)
                ->max('number')) + 1;

            $voucherId = DB::table('electronic_vouchers')->insertGetId([
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'type' => $type,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
                'sunat_ticket' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payloadSnapshot = app(BillingDocumentPayloadService::class)->createForVoucher($tenantId, $voucherId, $userId);
            $payload = array_merge(
                [
                    'voucher_id' => $voucherId,
                    'sale_id' => $sale->id,
                    'sale_reference' => $sale->reference,
                    'series' => $series,
                    'number' => $nextNumber,
                    'total_amount' => $sale->total_amount,
                ],
                app(BillingDocumentPayloadService::class)->outboxEnvelope($payloadSnapshot),
            );

            DB::table('outbox_events')->insert([
                'tenant_id' => $tenantId,
                'aggregate_type' => 'electronic_voucher',
                'aggregate_id' => $voucherId,
                'event_type' => 'voucher.created',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'billing',
                'billing.voucher.issued',
                'electronic_voucher',
                $voucherId,
                'Comprobante '.$series.'-'.$nextNumber.' emitido',
                [
                    'voucher_id' => $voucherId,
                    'sale_id' => $saleId,
                    'type' => $type,
                    'series' => $series,
                    'number' => $nextNumber,
                    'total_amount' => (float) $sale->total_amount,
                ],
            );

            return [
                'id' => $voucherId,
                'sale_id' => $saleId,
                'type' => $type,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
            ];
        });
    }
}
