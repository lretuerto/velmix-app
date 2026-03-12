<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesProfitabilityReportService
{
    public function summary(int $tenantId, ?string $date = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $start = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $end = $start->addDay();

        $summary = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as revenue_total, COALESCE(SUM(gross_cost), 0) as gross_cost_total, COALESCE(SUM(gross_margin), 0) as gross_margin_total')
            ->first();

        $products = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', $start)
            ->where('sales.created_at', '<', $end)
            ->groupBy('sale_items.product_id', 'products.sku', 'products.name')
            ->orderByRaw('SUM(sale_items.gross_margin) DESC')
            ->get([
                'sale_items.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.line_total) as revenue_total'),
                DB::raw('SUM(sale_items.cost_amount) as gross_cost_total'),
                DB::raw('SUM(sale_items.gross_margin) as gross_margin_total'),
            ])
            ->map(function (object $product) {
                $revenueTotal = round((float) $product->revenue_total, 2);
                $grossMarginTotal = round((float) $product->gross_margin_total, 2);

                return [
                    'product_id' => $product->product_id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity_sold' => (int) $product->quantity_sold,
                    'revenue_total' => $revenueTotal,
                    'gross_cost_total' => round((float) $product->gross_cost_total, 2),
                    'gross_margin_total' => $grossMarginTotal,
                    'margin_pct' => $revenueTotal > 0 ? round(($grossMarginTotal / $revenueTotal) * 100, 2) : 0.0,
                ];
            })
            ->all();

        $revenueTotal = round((float) ($summary->revenue_total ?? 0), 2);
        $grossMarginTotal = round((float) ($summary->gross_margin_total ?? 0), 2);

        return [
            'tenant_id' => $tenantId,
            'date' => $start->toDateString(),
            'summary' => [
                'sales_count' => (int) ($summary->sales_count ?? 0),
                'revenue_total' => $revenueTotal,
                'gross_cost_total' => round((float) ($summary->gross_cost_total ?? 0), 2),
                'gross_margin_total' => $grossMarginTotal,
                'margin_pct' => $revenueTotal > 0 ? round(($grossMarginTotal / $revenueTotal) * 100, 2) : 0.0,
            ],
            'products' => $products,
        ];
    }
}
