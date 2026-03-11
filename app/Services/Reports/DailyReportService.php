<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DailyReportService
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

        $completedSales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("
                COUNT(*) as aggregate_count,
                COALESCE(SUM(total_amount), 0) as aggregate_total,
                COALESCE(SUM(gross_cost), 0) as gross_cost_total,
                COALESCE(SUM(gross_margin), 0) as gross_margin_total,
                SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) as cash_count,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_total,
                SUM(CASE WHEN payment_method = 'card' THEN 1 ELSE 0 END) as card_count,
                COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_total,
                SUM(CASE WHEN payment_method = 'transfer' THEN 1 ELSE 0 END) as transfer_count,
                COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_total,
                SUM(CASE WHEN payment_method = 'credit' THEN 1 ELSE 0 END) as credit_count,
                COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_total
            ")
            ->first();

        $cancelledSales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'cancelled')
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $start)
            ->where('cancelled_at', '<', $end)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_amount), 0) as aggregate_total')
            ->first();

        $voucherCounts = DB::table('electronic_vouchers')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            ")
            ->first();

        $cashOpened = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('opened_at', '>=', $start)
            ->where('opened_at', '<', $end)
            ->count();

        $cashClosed = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(discrepancy_amount), 0) as discrepancy_total')
            ->first();

        $cashMovements = DB::table('cash_movements')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("
                COUNT(*) as movement_count,
                COALESCE(SUM(CASE WHEN type = 'manual_in' THEN amount ELSE 0 END), 0) as manual_in_total,
                COALESCE(SUM(CASE WHEN type = 'manual_out' THEN amount ELSE 0 END), 0) as manual_out_total
            ")
            ->first();

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', 'completed')
            ->where('sales.created_at', '>=', $start)
            ->where('sales.created_at', '<', $end)
            ->groupBy('sale_items.product_id', 'products.sku', 'products.name')
            ->orderByRaw('SUM(sale_items.line_total) DESC')
            ->limit(5)
            ->get([
                'sale_items.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(sale_items.quantity) as quantity_sold'),
                DB::raw('SUM(sale_items.line_total) as revenue_total'),
                DB::raw('SUM(sale_items.gross_margin) as gross_margin_total'),
            ])
            ->map(fn (object $product) => [
                'product_id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity_sold' => (int) $product->quantity_sold,
                'revenue_total' => round((float) $product->revenue_total, 2),
                'gross_margin_total' => round((float) $product->gross_margin_total, 2),
            ])
            ->all();

        $completedTotal = round((float) ($completedSales->aggregate_total ?? 0), 2);
        $grossCostTotal = round((float) ($completedSales->gross_cost_total ?? 0), 2);
        $grossMarginTotal = round((float) ($completedSales->gross_margin_total ?? 0), 2);

        return [
            'tenant_id' => $tenantId,
            'date' => $start->toDateString(),
            'sales' => [
                'completed_count' => (int) ($completedSales->aggregate_count ?? 0),
                'completed_total' => $completedTotal,
                'cancelled_count' => (int) ($cancelledSales->aggregate_count ?? 0),
                'cancelled_total' => round((float) ($cancelledSales->aggregate_total ?? 0), 2),
                'by_payment_method' => [
                    'cash' => [
                        'count' => (int) ($completedSales->cash_count ?? 0),
                        'total' => round((float) ($completedSales->cash_total ?? 0), 2),
                    ],
                    'card' => [
                        'count' => (int) ($completedSales->card_count ?? 0),
                        'total' => round((float) ($completedSales->card_total ?? 0), 2),
                    ],
                    'transfer' => [
                        'count' => (int) ($completedSales->transfer_count ?? 0),
                        'total' => round((float) ($completedSales->transfer_total ?? 0), 2),
                    ],
                    'credit' => [
                        'count' => (int) ($completedSales->credit_count ?? 0),
                        'total' => round((float) ($completedSales->credit_total ?? 0), 2),
                    ],
                ],
            ],
            'profitability' => [
                'gross_cost_total' => $grossCostTotal,
                'gross_margin_total' => $grossMarginTotal,
                'margin_pct' => $completedTotal > 0 ? round(($grossMarginTotal / $completedTotal) * 100, 2) : 0.0,
                'top_products' => $topProducts,
            ],
            'vouchers' => [
                'pending_count' => (int) ($voucherCounts->pending_count ?? 0),
                'accepted_count' => (int) ($voucherCounts->accepted_count ?? 0),
                'rejected_count' => (int) ($voucherCounts->rejected_count ?? 0),
                'failed_count' => (int) ($voucherCounts->failed_count ?? 0),
            ],
            'cash' => [
                'opened_count' => (int) $cashOpened,
                'closed_count' => (int) ($cashClosed->aggregate_count ?? 0),
                'discrepancy_total' => round((float) ($cashClosed->discrepancy_total ?? 0), 2),
                'movement_count' => (int) ($cashMovements->movement_count ?? 0),
                'manual_in_total' => round((float) ($cashMovements->manual_in_total ?? 0), 2),
                'manual_out_total' => round((float) ($cashMovements->manual_out_total ?? 0), 2),
            ],
        ];
    }
}
