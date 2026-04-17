<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryAlertReportService
{
    public function summary(int $tenantId, int $lowStockThreshold = 10, int $expiringWithinDays = 30): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($lowStockThreshold <= 0 || $expiringWithinDays <= 0) {
            throw new HttpException(422, 'Inventory alert thresholds must be valid.');
        }

        $today = CarbonImmutable::today();
        $expiryLimit = $today->addDays($expiringWithinDays)->toDateString();

        $lowStockProducts = DB::table('products')
            ->join('lots', 'lots.product_id', '=', 'products.id')
            ->where('products.tenant_id', $tenantId)
            ->where('lots.status', 'available')
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->havingRaw('SUM(lots.stock_quantity) <= ?', [$lowStockThreshold])
            ->orderByRaw('SUM(lots.stock_quantity) asc')
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                DB::raw('SUM(lots.stock_quantity) as total_stock'),
            ])
            ->map(fn (object $product) => [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'total_stock' => (int) $product->total_stock,
            ])
            ->all();

        $expiringLots = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.tenant_id', $tenantId)
            ->where('lots.status', 'available')
            ->whereDate('lots.expires_at', '>=', $today->toDateString())
            ->whereDate('lots.expires_at', '<=', $expiryLimit)
            ->orderBy('lots.expires_at')
            ->get([
                'lots.id',
                'lots.code',
                'lots.expires_at',
                'lots.stock_quantity',
                'lots.status',
                'products.sku as product_sku',
            ])
            ->map(function (object $lot) use ($today) {
                $expiresAt = CarbonImmutable::parse($lot->expires_at);

                return [
                    'lot_id' => $lot->id,
                    'code' => $lot->code,
                    'product_sku' => $lot->product_sku,
                    'expires_at' => $expiresAt->toDateString(),
                    'days_to_expiry' => $today->diffInDays($expiresAt, false),
                    'stock_quantity' => (int) $lot->stock_quantity,
                    'status' => $lot->status,
                ];
            })
            ->all();

        $immobilizedLots = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.tenant_id', $tenantId)
            ->where('lots.status', 'immobilized')
            ->orderBy('lots.code')
            ->get([
                'lots.id',
                'lots.code',
                'lots.expires_at',
                'lots.stock_quantity',
                'products.sku as product_sku',
            ])
            ->map(fn (object $lot) => [
                'lot_id' => $lot->id,
                'code' => $lot->code,
                'product_sku' => $lot->product_sku,
                'expires_at' => $lot->expires_at,
                'stock_quantity' => (int) $lot->stock_quantity,
            ])
            ->all();

        return [
            'tenant_id' => $tenantId,
            'thresholds' => [
                'low_stock_threshold' => $lowStockThreshold,
                'expiring_within_days' => $expiringWithinDays,
            ],
            'summary' => [
                'low_stock_products_count' => count($lowStockProducts),
                'expiring_lots_count' => count($expiringLots),
                'immobilized_lots_count' => count($immobilizedLots),
            ],
            'low_stock_products' => $lowStockProducts,
            'expiring_lots' => $expiringLots,
            'immobilized_lots' => $immobilizedLots,
        ];
    }
}
