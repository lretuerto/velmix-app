<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockMovementService
{
    public function create(int $tenantId, int $lotId, string $type, int $quantity, string $reference): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($quantity <= 0) {
            throw new HttpException(422, 'Quantity must be greater than zero.');
        }

        if (! in_array($type, ['manual_in', 'manual_out'], true)) {
            throw new HttpException(422, 'Movement type is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $lotId, $type, $quantity, $reference) {
            $lot = DB::table('lots')
                ->join('products', 'products.id', '=', 'lots.product_id')
                ->where('lots.id', $lotId)
                ->where('lots.tenant_id', $tenantId)
                ->lockForUpdate()
                ->first([
                    'lots.id',
                    'lots.tenant_id',
                    'lots.product_id',
                    'lots.code',
                    'lots.stock_quantity',
                    'products.sku',
                ]);

            if ($lot === null) {
                throw new HttpException(404, 'Lot not found.');
            }

            $signedQuantity = $type === 'manual_in' ? $quantity : -$quantity;
            $resultingStock = $type === 'manual_in'
                ? app(LotStockMutationService::class)->incrementLockedLot($lot, $quantity)
                : app(LotStockMutationService::class)->decrementLockedLot($lot, $quantity);

            $movementId = DB::table('stock_movements')->insertGetId([
                'tenant_id' => $tenantId,
                'lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'sale_id' => null,
                'type' => $type,
                'quantity' => $signedQuantity,
                'reference' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'movement_id' => $movementId,
                'lot_id' => $lot->id,
                'product_sku' => $lot->sku,
                'type' => $type,
                'quantity' => $signedQuantity,
                'resulting_stock' => $resultingStock,
                'reference' => $reference,
            ];
        });
    }
}
