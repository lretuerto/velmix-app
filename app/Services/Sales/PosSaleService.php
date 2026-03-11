<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosSaleService
{
    public function execute(int $tenantId, int $userId, int $lotId, int $quantity, float $unitPrice): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($quantity <= 0 || $unitPrice < 0) {
            throw new HttpException(422, 'Quantity and unit price must be valid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $lotId, $quantity, $unitPrice) {
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
                    'products.name',
                ]);

            if ($lot === null) {
                throw new HttpException(404, 'Lot not found.');
            }

            if ($lot->stock_quantity < $quantity) {
                throw new HttpException(422, 'Insufficient stock for lot.');
            }

            $reference = 'SALE-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $lineTotal = round($quantity * $unitPrice, 2);

            $saleId = DB::table('sales')->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'reference' => $reference,
                'status' => 'completed',
                'total_amount' => $lineTotal,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lots')
                ->where('id', $lot->id)
                ->update([
                    'stock_quantity' => $lot->stock_quantity - $quantity,
                    'updated_at' => now(),
                ]);

            DB::table('stock_movements')->insert([
                'tenant_id' => $tenantId,
                'lot_id' => $lot->id,
                'product_id' => $lot->product_id,
                'sale_id' => $saleId,
                'type' => 'sale',
                'quantity' => -$quantity,
                'reference' => $reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'sale_id' => $saleId,
                'reference' => $reference,
                'lot_id' => $lot->id,
                'product_sku' => $lot->sku,
                'quantity' => $quantity,
                'remaining_stock' => $lot->stock_quantity - $quantity,
                'total_amount' => $lineTotal,
            ];
        });
    }
}
