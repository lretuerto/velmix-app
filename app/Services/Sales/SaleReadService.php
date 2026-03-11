<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleReadService
{
    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('sales')
            ->leftJoin('electronic_vouchers', 'electronic_vouchers.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenantId)
            ->orderByDesc('sales.id')
            ->get([
                'sales.id',
                'sales.reference',
                'sales.status',
                'sales.total_amount',
                'sales.cancel_reason',
                'sales.cancelled_at',
                'electronic_vouchers.id as voucher_id',
                'electronic_vouchers.status as voucher_status',
            ])
            ->map(fn (object $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status,
                'total_amount' => (float) $sale->total_amount,
                'cancel_reason' => $sale->cancel_reason,
                'cancelled_at' => $sale->cancelled_at,
                'voucher_id' => $sale->voucher_id,
                'voucher_status' => $sale->voucher_status,
            ])
            ->all();
    }

    public function detail(int $tenantId, int $saleId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $sale = DB::table('sales')
            ->leftJoin('electronic_vouchers', 'electronic_vouchers.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.id', $saleId)
            ->first([
                'sales.id',
                'sales.reference',
                'sales.status',
                'sales.total_amount',
                'sales.cancel_reason',
                'sales.cancelled_at',
                'electronic_vouchers.id as voucher_id',
                'electronic_vouchers.status as voucher_status',
                'electronic_vouchers.series as voucher_series',
                'electronic_vouchers.number as voucher_number',
            ]);

        if ($sale === null) {
            throw new HttpException(404, 'Sale not found.');
        }

        $items = DB::table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->join('lots', 'lots.id', '=', 'sale_items.lot_id')
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.id',
                'sale_items.quantity',
                'sale_items.unit_price',
                'sale_items.line_total',
                'sale_items.prescription_code',
                'sale_items.approval_code',
                'products.sku as product_sku',
                'lots.code as lot_code',
            ])
            ->map(fn (object $item) => [
                'id' => $item->id,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'prescription_code' => $item->prescription_code,
                'approval_code' => $item->approval_code,
                'product_sku' => $item->product_sku,
                'lot_code' => $item->lot_code,
            ])
            ->all();

        $movementCount = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('sale_id', $saleId)
            ->count();

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'status' => $sale->status,
            'total_amount' => (float) $sale->total_amount,
            'cancel_reason' => $sale->cancel_reason,
            'cancelled_at' => $sale->cancelled_at,
            'voucher' => $sale->voucher_id !== null ? [
                'id' => $sale->voucher_id,
                'status' => $sale->voucher_status,
                'series' => $sale->voucher_series,
                'number' => $sale->voucher_number,
            ] : null,
            'movement_count' => $movementCount,
            'items' => $items,
        ];
    }
}
