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

        $sales = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('electronic_vouchers', 'electronic_vouchers.sale_id', '=', 'sales.id')
            ->leftJoin('sale_receivables', 'sale_receivables.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenantId)
            ->orderByDesc('sales.id')
            ->get([
                'sales.id',
                'sales.reference',
                'sales.status',
                'sales.payment_method',
                'sales.total_amount',
                'sales.gross_cost',
                'sales.gross_margin',
                'sales.cancel_reason',
                'sales.cancelled_at',
                'sales.credit_reason',
                'sales.credited_at',
                'customers.id as customer_id',
                'customers.document_type as customer_document_type',
                'customers.document_number as customer_document_number',
                'customers.name as customer_name',
                'sale_receivables.id as receivable_id',
                'sale_receivables.status as receivable_status',
                'sale_receivables.outstanding_amount as receivable_outstanding_amount',
                'electronic_vouchers.id as voucher_id',
                'electronic_vouchers.status as voucher_status',
            ]);

        $creditSummaries = $this->creditNoteSummaries($sales->pluck('id')->all());

        return $sales->map(function (object $sale) use ($creditSummaries) {
            $creditSummary = $creditSummaries[$sale->id] ?? null;

            return [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'total_amount' => (float) $sale->total_amount,
                'gross_cost' => (float) $sale->gross_cost,
                'gross_margin' => (float) $sale->gross_margin,
                'cancel_reason' => $sale->cancel_reason,
                'cancelled_at' => $sale->cancelled_at,
                'credit_reason' => $sale->credit_reason,
                'credited_at' => $sale->credited_at,
                'customer' => $sale->customer_id !== null ? [
                    'id' => $sale->customer_id,
                    'document_type' => $sale->customer_document_type,
                    'document_number' => $sale->customer_document_number,
                    'name' => $sale->customer_name,
                ] : null,
                'receivable' => $sale->receivable_id !== null ? [
                    'id' => $sale->receivable_id,
                    'status' => $sale->receivable_status,
                    'outstanding_amount' => (float) $sale->receivable_outstanding_amount,
                ] : null,
                'voucher_id' => $sale->voucher_id,
                'voucher_status' => $sale->voucher_status,
                'credit_note' => $creditSummary !== null ? [
                    'id' => $creditSummary['latest_id'],
                    'status' => $creditSummary['latest_status'],
                ] : null,
                'credit_summary' => $creditSummary,
            ];
        })
            ->all();
    }

    public function detail(int $tenantId, int $saleId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $sale = DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('electronic_vouchers', 'electronic_vouchers.sale_id', '=', 'sales.id')
            ->leftJoin('sale_receivables', 'sale_receivables.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.id', $saleId)
            ->first([
                'sales.id',
                'sales.reference',
                'sales.status',
                'sales.payment_method',
                'sales.total_amount',
                'sales.gross_cost',
                'sales.gross_margin',
                'sales.cancel_reason',
                'sales.cancelled_at',
                'sales.credit_reason',
                'sales.credited_at',
                'customers.id as customer_id',
                'customers.document_type as customer_document_type',
                'customers.document_number as customer_document_number',
                'customers.name as customer_name',
                'sale_receivables.id as receivable_id',
                'sale_receivables.status as receivable_status',
                'sale_receivables.due_at as receivable_due_at',
                'sale_receivables.outstanding_amount as receivable_outstanding_amount',
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
            ->leftJoinSub(
                DB::table('sale_credit_note_items')
                    ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) as credited_quantity')
                    ->groupBy('sale_item_id'),
                'credited_items',
                'credited_items.sale_item_id',
                '=',
                'sale_items.id'
            )
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.id',
                'sale_items.quantity',
                'sale_items.unit_price',
                'sale_items.unit_cost_snapshot',
                'sale_items.line_total',
                'sale_items.cost_amount',
                'sale_items.gross_margin',
                'sale_items.prescription_code',
                'sale_items.approval_code',
                'products.sku as product_sku',
                'lots.code as lot_code',
                DB::raw('COALESCE(credited_items.credited_quantity, 0) as credited_quantity'),
            ])
            ->map(fn (object $item) => [
                'id' => $item->id,
                'quantity' => (int) $item->quantity,
                'credited_quantity' => (int) $item->credited_quantity,
                'remaining_quantity' => max((int) $item->quantity - (int) $item->credited_quantity, 0),
                'unit_price' => (float) $item->unit_price,
                'unit_cost_snapshot' => (float) $item->unit_cost_snapshot,
                'line_total' => (float) $item->line_total,
                'cost_amount' => (float) $item->cost_amount,
                'gross_margin' => (float) $item->gross_margin,
                'prescription_code' => $item->prescription_code,
                'approval_code' => $item->approval_code,
                'product_sku' => $item->product_sku,
                'lot_code' => $item->lot_code,
            ])
            ->all();

        $creditNotes = DB::table('sale_credit_notes')
            ->where('sale_id', $saleId)
            ->orderByDesc('id')
            ->get([
                'id',
                'series',
                'number',
                'status',
                'reason',
                'total_amount',
                'refunded_amount',
                'refund_payment_method',
                'created_at',
            ])
            ->map(fn (object $creditNote) => [
                'id' => $creditNote->id,
                'series' => $creditNote->series,
                'number' => $creditNote->number,
                'status' => $creditNote->status,
                'reason' => $creditNote->reason,
                'total_amount' => (float) $creditNote->total_amount,
                'refunded_amount' => (float) $creditNote->refunded_amount,
                'refund_payment_method' => $creditNote->refund_payment_method,
                'created_at' => $creditNote->created_at,
            ])
            ->all();

        $creditSummary = $this->creditNoteSummaries([$saleId])[$saleId] ?? null;

        $movementCount = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('sale_id', $saleId)
            ->count();

        return [
            'id' => $sale->id,
            'reference' => $sale->reference,
            'status' => $sale->status,
            'payment_method' => $sale->payment_method,
            'total_amount' => (float) $sale->total_amount,
            'gross_cost' => (float) $sale->gross_cost,
            'gross_margin' => (float) $sale->gross_margin,
            'cancel_reason' => $sale->cancel_reason,
            'cancelled_at' => $sale->cancelled_at,
            'credit_reason' => $sale->credit_reason,
            'credited_at' => $sale->credited_at,
            'customer' => $sale->customer_id !== null ? [
                'id' => $sale->customer_id,
                'document_type' => $sale->customer_document_type,
                'document_number' => $sale->customer_document_number,
                'name' => $sale->customer_name,
            ] : null,
            'receivable' => $sale->receivable_id !== null ? [
                'id' => $sale->receivable_id,
                'status' => $sale->receivable_status,
                'due_at' => $sale->receivable_due_at,
                'outstanding_amount' => (float) $sale->receivable_outstanding_amount,
            ] : null,
            'voucher' => $sale->voucher_id !== null ? [
                'id' => $sale->voucher_id,
                'status' => $sale->voucher_status,
                'series' => $sale->voucher_series,
                'number' => $sale->voucher_number,
            ] : null,
            'credit_note' => $creditSummary !== null ? [
                'id' => $creditSummary['latest_id'],
                'status' => $creditSummary['latest_status'],
                'series' => $creditSummary['latest_series'],
                'number' => $creditSummary['latest_number'],
            ] : null,
            'credit_summary' => $creditSummary,
            'credit_notes' => $creditNotes,
            'movement_count' => $movementCount,
            'items' => $items,
        ];
    }

    private function creditNoteSummaries(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $summaries = DB::table('sale_credit_notes')
            ->whereIn('sale_id', $saleIds)
            ->orderBy('id')
            ->get([
                'id',
                'sale_id',
                'series',
                'number',
                'status',
                'total_amount',
                'refunded_amount',
            ])
            ->groupBy('sale_id');

        return $summaries->mapWithKeys(function ($notes, $saleId) {
            $latest = $notes->last();

            return [(int) $saleId => [
                'count' => $notes->count(),
                'credited_total' => round($notes->sum(fn (object $note) => (float) $note->total_amount), 2),
                'refunded_total' => round($notes->sum(fn (object $note) => (float) $note->refunded_amount), 2),
                'latest_id' => $latest->id,
                'latest_status' => $latest->status,
                'latest_series' => $latest->series,
                'latest_number' => $latest->number,
            ]];
        })->all();
    }
}
