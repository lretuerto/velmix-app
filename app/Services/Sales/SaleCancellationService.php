<?php

namespace App\Services\Sales;

use App\Services\Audit\TenantActivityLogService;
use App\Services\Inventory\LotStockMutationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleCancellationService
{
    public function cancel(int $tenantId, int $userId, int $saleId, string $reason): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'Cancellation reason is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $saleId, $reason) {
            $sale = DB::table('sales')
                ->where('id', $saleId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id', 'reference', 'status']);

            if ($sale === null) {
                throw new HttpException(404, 'Sale not found.');
            }

            if ($sale->status !== 'completed') {
                throw new HttpException(422, 'Only completed sales can be cancelled.');
            }

            $hasVoucher = DB::table('electronic_vouchers')
                ->where('sale_id', $saleId)
                ->exists();

            if ($hasVoucher) {
                throw new HttpException(422, 'Sales with vouchers cannot be cancelled.');
            }

            $receivable = DB::table('sale_receivables')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'paid_amount']);

            if ($receivable !== null && (float) $receivable->paid_amount > 0) {
                throw new HttpException(422, 'Sales with customer payments cannot be cancelled.');
            }

            $items = DB::table('sale_items')
                ->where('sale_id', $saleId)
                ->get(['lot_id', 'product_id', 'quantity']);

            foreach ($items as $item) {
                $lot = DB::table('lots')
                    ->where('id', $item->lot_id)
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->first(['id', 'stock_quantity']);

                if ($lot === null) {
                    throw new HttpException(404, 'Lot not found during cancellation.');
                }

                app(LotStockMutationService::class)->incrementLockedLot(
                    $lot,
                    (int) $item->quantity,
                    'Lot not found during cancellation.',
                );

                DB::table('stock_movements')->insert([
                    'tenant_id' => $tenantId,
                    'lot_id' => $item->lot_id,
                    'product_id' => $item->product_id,
                    'sale_id' => $saleId,
                    'type' => 'sale_reversal',
                    'quantity' => (int) $item->quantity,
                    'reference' => $sale->reference.'-VOID',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_by_user_id' => $userId,
                    'cancel_reason' => $reason,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($receivable !== null) {
                DB::table('sale_receivables')
                    ->where('id', $receivable->id)
                    ->delete();
            }

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'sales',
                'sales.sale.cancelled',
                'sale',
                $saleId,
                'Venta '.$sale->reference.' anulada',
                [
                    'reference' => $sale->reference,
                    'reason' => $reason,
                    'restored_item_count' => $items->count(),
                ],
            );

            return [
                'sale_id' => $saleId,
                'status' => 'cancelled',
                'reason' => $reason,
            ];
        });
    }
}
