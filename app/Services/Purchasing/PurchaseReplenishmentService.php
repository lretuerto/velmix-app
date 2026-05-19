<?php

namespace App\Services\Purchasing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseReplenishmentService
{
    public function suggestions(
        int $tenantId,
        int $lookbackDays = 30,
        int $coverageDays = 30,
        int $expiringWithinDays = 30,
        int $lowStockThreshold = 20,
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($lookbackDays <= 0 || $coverageDays <= 0 || $expiringWithinDays <= 0 || $lowStockThreshold <= 0) {
            throw new HttpException(422, 'Replenishment parameters must be valid.');
        }

        $today = CarbonImmutable::today();
        $lookbackStart = $today->subDays($lookbackDays);
        $expiringLimit = $today->addDays($expiringWithinDays)->toDateString();

        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('sku')
            ->get(['id', 'sku', 'name']);

        $suggestions = [];

        foreach ($products as $product) {
            $currentStock = (int) DB::table('lots')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('status', 'available')
                ->sum('stock_quantity');

            $expiringSoonStock = (int) DB::table('lots')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('status', 'available')
                ->whereDate('expires_at', '>=', $today->toDateString())
                ->whereDate('expires_at', '<=', $expiringLimit)
                ->sum('stock_quantity');

            $recentSalesQuantity = abs((int) DB::table('stock_movements')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $product->id)
                ->where('type', 'sale')
                ->where('created_at', '>=', $lookbackStart)
                ->sum('quantity'));

            $avgDailyConsumption = round($recentSalesQuantity / $lookbackDays, 2);
            $projectedDemand = (int) ceil($avgDailyConsumption * $coverageDays);
            $usableStock = max(0, $currentStock - $expiringSoonStock);
            $suggestedOrderQuantity = max(0, $projectedDemand - $usableStock);

            if ($suggestedOrderQuantity <= 0 && $currentStock > $lowStockThreshold && $expiringSoonStock <= 0) {
                continue;
            }

            $reason = $suggestedOrderQuantity > 0
                ? 'projected_demand'
                : ($expiringSoonStock > 0 ? 'expiring_stock' : 'low_stock');

            $suggestions[] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'current_stock' => $currentStock,
                'usable_stock' => $usableStock,
                'expiring_soon_stock' => $expiringSoonStock,
                'recent_sales_quantity' => $recentSalesQuantity,
                'avg_daily_consumption' => $avgDailyConsumption,
                'projected_demand' => $projectedDemand,
                'suggested_order_quantity' => $suggestedOrderQuantity,
                'reason' => $reason,
            ];
        }

        usort($suggestions, function (array $left, array $right) {
            $bySuggested = $right['suggested_order_quantity'] <=> $left['suggested_order_quantity'];

            if ($bySuggested !== 0) {
                return $bySuggested;
            }

            return $right['expiring_soon_stock'] <=> $left['expiring_soon_stock'];
        });

        return [
            'tenant_id' => $tenantId,
            'parameters' => [
                'lookback_days' => $lookbackDays,
                'coverage_days' => $coverageDays,
                'expiring_within_days' => $expiringWithinDays,
                'low_stock_threshold' => $lowStockThreshold,
            ],
            'data' => $suggestions,
        ];
    }
}
