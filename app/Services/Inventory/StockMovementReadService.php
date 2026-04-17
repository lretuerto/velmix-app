<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockMovementReadService
{
    public function list(int $tenantId, array $filters = []): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $query = DB::table('stock_movements')
            ->join('lots', 'lots.id', '=', 'stock_movements.lot_id')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('stock_movements.tenant_id', $tenantId)
            ->orderByDesc('stock_movements.id');

        if (isset($filters['lot_id'])) {
            $query->where('stock_movements.lot_id', (int) $filters['lot_id']);
        }

        if (isset($filters['product_id'])) {
            $query->where('stock_movements.product_id', (int) $filters['product_id']);
        }

        if (isset($filters['type'])) {
            $query->where('stock_movements.type', (string) $filters['type']);
        }

        return $query->get([
            'stock_movements.id',
            'stock_movements.sale_id',
            'stock_movements.type',
            'stock_movements.quantity',
            'stock_movements.reference',
            'stock_movements.created_at',
            'lots.id as lot_id',
            'lots.code as lot_code',
            'products.id as product_id',
            'products.sku as product_sku',
        ])
            ->map(fn (object $movement) => [
                'id' => $movement->id,
                'sale_id' => $movement->sale_id,
                'type' => $movement->type,
                'quantity' => (int) $movement->quantity,
                'reference' => $movement->reference,
                'created_at' => $movement->created_at,
                'lot' => [
                    'id' => $movement->lot_id,
                    'code' => $movement->lot_code,
                ],
                'product' => [
                    'id' => $movement->product_id,
                    'sku' => $movement->product_sku,
                ],
            ])
            ->all();
    }
}
