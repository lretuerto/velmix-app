<?php

namespace App\Services\Purchasing;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseReturnService
{
    public function create(int $tenantId, int $userId, int $receiptId, string $reason, array $requestedItems = []): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'Purchase return reason is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $receiptId, $reason, $requestedItems) {
            $receipt = DB::table('purchase_receipts')
                ->where('tenant_id', $tenantId)
                ->where('id', $receiptId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'supplier_id',
                    'purchase_order_id',
                    'reference',
                    'status',
                    'total_amount',
                ]);

            if ($receipt === null) {
                throw new HttpException(404, 'Purchase receipt not found.');
            }

            if (! in_array($receipt->status, ['received', 'partially_returned'], true)) {
                throw new HttpException(422, 'Purchase receipt cannot be returned.');
            }

            $supplier = DB::table('suppliers')
                ->where('tenant_id', $tenantId)
                ->where('id', $receipt->supplier_id)
                ->first(['id', 'name']);

            $receiptItems = DB::table('purchase_receipt_items')
                ->where('purchase_receipt_id', $receiptId)
                ->orderBy('id')
                ->get([
                    'id',
                    'lot_id',
                    'product_id',
                    'quantity',
                    'unit_cost',
                    'line_total',
                ]);

            if ($receiptItems->isEmpty()) {
                throw new HttpException(422, 'Purchase receipt has no items to return.');
            }

            $returnedQuantities = DB::table('purchase_return_items')
                ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
                ->where('purchase_returns.purchase_receipt_id', $receiptId)
                ->selectRaw('purchase_receipt_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
                ->groupBy('purchase_receipt_item_id')
                ->pluck('returned_quantity', 'purchase_receipt_item_id');

            $priorReturnedTotal = round((float) DB::table('purchase_returns')
                ->where('purchase_receipt_id', $receiptId)
                ->sum('total_amount'), 2);

            $targetItems = $this->resolveTargetItems($receiptItems, $returnedQuantities, $requestedItems);
            $returnTotal = round(collect($targetItems)->sum('line_total'), 2);

            $returnId = DB::table('purchase_returns')->insertGetId([
                'tenant_id' => $tenantId,
                'supplier_id' => $receipt->supplier_id,
                'purchase_receipt_id' => $receiptId,
                'purchase_payable_id' => null,
                'user_id' => $userId,
                'reference' => 'PENDING',
                'status' => 'processed',
                'reason' => $reason,
                'total_amount' => $returnTotal,
                'returned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reference = 'PRT-'.str_pad((string) $returnId, 6, '0', STR_PAD_LEFT);

            DB::table('purchase_returns')
                ->where('id', $returnId)
                ->update([
                    'reference' => $reference,
                    'updated_at' => now(),
                ]);

            foreach ($targetItems as $item) {
                $lot = DB::table('lots')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $item['lot_id'])
                    ->lockForUpdate()
                    ->first(['id', 'stock_quantity']);

                if ($lot === null) {
                    throw new HttpException(404, 'Lot not found during purchase return.');
                }

                if ((int) $lot->stock_quantity < $item['quantity']) {
                    throw new HttpException(422, 'Purchase return exceeds current stock for lot.');
                }

                DB::table('lots')
                    ->where('id', $lot->id)
                    ->update([
                        'stock_quantity' => (int) $lot->stock_quantity - $item['quantity'],
                        'updated_at' => now(),
                    ]);

                DB::table('purchase_return_items')->insert([
                    'purchase_return_id' => $returnId,
                    'purchase_receipt_item_id' => $item['purchase_receipt_item_id'],
                    'lot_id' => $item['lot_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'tenant_id' => $tenantId,
                    'lot_id' => $item['lot_id'],
                    'product_id' => $item['product_id'],
                    'sale_id' => null,
                    'type' => 'purchase_return',
                    'quantity' => -$item['quantity'],
                    'reference' => $reference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $returnItemCollection = collect($targetItems);
            $remainingAmount = round((float) $receipt->total_amount - $priorReturnedTotal - $returnTotal, 2);
            $receiptStatus = $remainingAmount <= 0 ? 'returned' : 'partially_returned';

            DB::table('purchase_receipts')
                ->where('id', $receiptId)
                ->update([
                    'status' => $receiptStatus,
                    'updated_at' => now(),
                ]);

            if ($receipt->purchase_order_id !== null) {
                $this->adjustPurchaseOrderReceivedQuantities((int) $receipt->purchase_order_id, $returnItemCollection);
            }

            $affectedProductIds = $returnItemCollection->pluck('product_id')->unique()->values()->all();
            foreach ($affectedProductIds as $productId) {
                $this->recalculateProductCosts($tenantId, (int) $productId);
            }

            $payable = DB::table('purchase_payables')
                ->where('purchase_receipt_id', $receiptId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id', 'supplier_id']);

            $supplierCreditAmount = 0.0;

            if ($payable !== null) {
                DB::table('purchase_returns')
                    ->where('id', $returnId)
                    ->update([
                        'purchase_payable_id' => $payable->id,
                        'updated_at' => now(),
                    ]);

                $supplierCreditAmount = $this->adjustPayableForReturn(
                    $tenantId,
                    $payable->id,
                    $receiptId,
                    $returnId,
                    $receipt->supplier_id,
                    $reference,
                );
            }

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'purchasing',
                'purchasing.return.processed',
                'purchase_return',
                $returnId,
                'Devolucion a proveedor '.$reference.' procesada',
                [
                    'purchase_return_id' => $returnId,
                    'reference' => $reference,
                    'purchase_receipt_id' => $receiptId,
                    'supplier_id' => $receipt->supplier_id,
                    'reason' => $reason,
                    'total_amount' => $returnTotal,
                    'supplier_credit_amount' => $supplierCreditAmount,
                    'item_count' => count($targetItems),
                ],
            );

            return [
                'id' => $returnId,
                'reference' => $reference,
                'purchase_receipt_id' => $receiptId,
                'supplier' => [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                ],
                'status' => 'processed',
                'reason' => $reason,
                'total_amount' => $returnTotal,
                'supplier_credit_amount' => $supplierCreditAmount,
                'items' => array_map(fn (array $item) => [
                    'purchase_receipt_item_id' => $item['purchase_receipt_item_id'],
                    'lot_id' => $item['lot_id'],
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ], $targetItems),
            ];
        });
    }

    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('purchase_returns')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_returns.purchase_receipt_id')
            ->where('purchase_returns.tenant_id', $tenantId)
            ->orderByDesc('purchase_returns.id')
            ->get([
                'purchase_returns.id',
                'purchase_returns.reference',
                'purchase_returns.status',
                'purchase_returns.reason',
                'purchase_returns.total_amount',
                'purchase_returns.returned_at',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ])
            ->map(fn (object $return) => [
                'id' => $return->id,
                'reference' => $return->reference,
                'status' => $return->status,
                'reason' => $return->reason,
                'total_amount' => (float) $return->total_amount,
                'returned_at' => $return->returned_at,
                'supplier_name' => $return->supplier_name,
                'receipt_reference' => $return->receipt_reference,
            ])
            ->all();
    }

    public function detail(int $tenantId, int $returnId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $return = DB::table('purchase_returns')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_returns.purchase_receipt_id')
            ->where('purchase_returns.tenant_id', $tenantId)
            ->where('purchase_returns.id', $returnId)
            ->first([
                'purchase_returns.id',
                'purchase_returns.reference',
                'purchase_returns.status',
                'purchase_returns.reason',
                'purchase_returns.total_amount',
                'purchase_returns.returned_at',
                'purchase_returns.purchase_receipt_id',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
                'suppliers.tax_id as supplier_tax_id',
                'purchase_receipts.reference as receipt_reference',
            ]);

        if ($return === null) {
            throw new HttpException(404, 'Purchase return not found.');
        }

        $items = DB::table('purchase_return_items')
            ->join('products', 'products.id', '=', 'purchase_return_items.product_id')
            ->join('lots', 'lots.id', '=', 'purchase_return_items.lot_id')
            ->where('purchase_return_items.purchase_return_id', $returnId)
            ->orderBy('purchase_return_items.id')
            ->get([
                'purchase_return_items.purchase_receipt_item_id',
                'purchase_return_items.quantity',
                'purchase_return_items.unit_cost',
                'purchase_return_items.line_total',
                'products.sku as product_sku',
                'lots.code as lot_code',
            ])
            ->map(fn (object $item) => [
                'purchase_receipt_item_id' => $item->purchase_receipt_item_id,
                'quantity' => (int) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
                'product_sku' => $item->product_sku,
                'lot_code' => $item->lot_code,
            ])
            ->all();

        $supplierCredits = DB::table('supplier_credits')
            ->where('purchase_return_id', $returnId)
            ->orderBy('id')
            ->get(['id', 'amount', 'remaining_amount', 'status', 'reference'])
            ->map(fn (object $credit) => [
                'id' => $credit->id,
                'amount' => (float) $credit->amount,
                'remaining_amount' => (float) $credit->remaining_amount,
                'status' => $credit->status,
                'reference' => $credit->reference,
            ])
            ->all();

        return [
            'id' => $return->id,
            'reference' => $return->reference,
            'status' => $return->status,
            'reason' => $return->reason,
            'total_amount' => (float) $return->total_amount,
            'returned_at' => $return->returned_at,
            'purchase_receipt' => [
                'id' => $return->purchase_receipt_id,
                'reference' => $return->receipt_reference,
            ],
            'supplier' => [
                'id' => $return->supplier_id,
                'tax_id' => $return->supplier_tax_id,
                'name' => $return->supplier_name,
            ],
            'items' => $items,
            'supplier_credits' => $supplierCredits,
        ];
    }

    private function resolveTargetItems(Collection $receiptItems, $returnedQuantities, array $requestedItems): array
    {
        $itemsById = $receiptItems->keyBy('id');

        $remaining = $receiptItems->mapWithKeys(function (object $item) use ($returnedQuantities) {
            $returned = (int) ($returnedQuantities[$item->id] ?? 0);

            return [$item->id => max((int) $item->quantity - $returned, 0)];
        });

        if ($requestedItems === []) {
            $resolved = [];

            foreach ($receiptItems as $item) {
                $quantity = (int) ($remaining[$item->id] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                $resolved[] = [
                    'purchase_receipt_item_id' => $item->id,
                    'lot_id' => $item->lot_id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'unit_cost' => round((float) $item->unit_cost, 2),
                    'line_total' => round($quantity * (float) $item->unit_cost, 2),
                ];
            }

            if ($resolved === []) {
                throw new HttpException(422, 'Purchase receipt has no remaining quantities to return.');
            }

            return $resolved;
        }

        $resolved = [];
        $requestedByItem = [];

        foreach ($requestedItems as $requestedItem) {
            $receiptItemId = (int) ($requestedItem['purchase_receipt_item_id'] ?? 0);
            $quantity = (int) ($requestedItem['quantity'] ?? 0);

            if ($receiptItemId <= 0 || $quantity <= 0) {
                throw new HttpException(422, 'Purchase return items are invalid.');
            }

            $receiptItem = $itemsById->get($receiptItemId);

            if ($receiptItem === null) {
                throw new HttpException(404, 'Purchase receipt item not found.');
            }

            $requestedByItem[$receiptItemId] = ($requestedByItem[$receiptItemId] ?? 0) + $quantity;
            $remainingQuantity = (int) ($remaining[$receiptItemId] ?? 0);

            if ($requestedByItem[$receiptItemId] > $remainingQuantity) {
                throw new HttpException(422, 'Purchase return quantity exceeds remaining receipt quantity.');
            }

            $resolved[] = [
                'purchase_receipt_item_id' => $receiptItem->id,
                'lot_id' => $receiptItem->lot_id,
                'product_id' => $receiptItem->product_id,
                'quantity' => $quantity,
                'unit_cost' => round((float) $receiptItem->unit_cost, 2),
                'line_total' => round($quantity * (float) $receiptItem->unit_cost, 2),
            ];
        }

        return $resolved;
    }

    private function adjustPurchaseOrderReceivedQuantities(int $purchaseOrderId, Collection $items): void
    {
        $returnedByProduct = $items
            ->groupBy('product_id')
            ->map(fn (Collection $group) => $group->sum('quantity'));

        foreach ($returnedByProduct as $productId => $quantity) {
            $orderItem = DB::table('purchase_order_items')
                ->where('purchase_order_id', $purchaseOrderId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first(['id', 'received_quantity']);

            if ($orderItem === null) {
                continue;
            }

            DB::table('purchase_order_items')
                ->where('id', $orderItem->id)
                ->update([
                    'received_quantity' => max((int) $orderItem->received_quantity - (int) $quantity, 0),
                    'updated_at' => now(),
                ]);
        }

        $totals = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->selectRaw('SUM(ordered_quantity) as ordered_total, SUM(received_quantity) as received_total')
            ->first();

        $orderedTotal = (int) ($totals->ordered_total ?? 0);
        $receivedTotal = (int) ($totals->received_total ?? 0);
        $orderStatus = $receivedTotal <= 0
            ? 'open'
            : ($receivedTotal < $orderedTotal ? 'partially_received' : 'received');

        DB::table('purchase_orders')
            ->where('id', $purchaseOrderId)
            ->update([
                'status' => $orderStatus,
                'received_at' => $orderStatus === 'received' ? now() : null,
                'updated_at' => now(),
            ]);
    }

    private function recalculateProductCosts(int $tenantId, int $productId): void
    {
        $returnSummary = DB::table('purchase_return_items')
            ->selectRaw('purchase_receipt_item_id, COALESCE(SUM(quantity), 0) as returned_quantity')
            ->groupBy('purchase_receipt_item_id');

        $summary = DB::table('purchase_receipt_items')
            ->leftJoinSub($returnSummary, 'returned_items', 'returned_items.purchase_receipt_item_id', '=', 'purchase_receipt_items.id')
            ->where('purchase_receipt_items.product_id', $productId)
            ->selectRaw('
                COALESCE(SUM(purchase_receipt_items.quantity - COALESCE(returned_items.returned_quantity, 0)), 0) as net_quantity,
                COALESCE(SUM((purchase_receipt_items.quantity - COALESCE(returned_items.returned_quantity, 0)) * purchase_receipt_items.unit_cost), 0) as net_cost
            ')
            ->first();

        $lastCost = (float) DB::table('purchase_receipt_items')
            ->leftJoinSub($returnSummary, 'returned_items', 'returned_items.purchase_receipt_item_id', '=', 'purchase_receipt_items.id')
            ->where('purchase_receipt_items.product_id', $productId)
            ->whereRaw('(purchase_receipt_items.quantity - COALESCE(returned_items.returned_quantity, 0)) > 0')
            ->orderByDesc('purchase_receipt_items.id')
            ->value('purchase_receipt_items.unit_cost');

        $netQuantity = round((float) ($summary->net_quantity ?? 0), 2);
        $averageCost = $netQuantity > 0
            ? round(((float) ($summary->net_cost ?? 0)) / $netQuantity, 2)
            : 0.0;

        DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->update([
                'last_cost' => round($lastCost, 2),
                'average_cost' => $averageCost,
                'updated_at' => now(),
            ]);
    }

    private function adjustPayableForReturn(
        int $tenantId,
        int $payableId,
        int $receiptId,
        int $returnId,
        int $supplierId,
        string $reference
    ): float {
        $receiptTotal = round((float) DB::table('purchase_receipts')
            ->where('id', $receiptId)
            ->value('total_amount'), 2);
        $returnedTotal = round((float) DB::table('purchase_returns')
            ->where('purchase_receipt_id', $receiptId)
            ->sum('total_amount'), 2);
        $cashPaid = round((float) DB::table('purchase_payments')
            ->where('purchase_payable_id', $payableId)
            ->sum('amount'), 2);
        $appliedCredits = round((float) DB::table('supplier_credit_applications')
            ->where('purchase_payable_id', $payableId)
            ->sum('amount'), 2);
        $existingCreditTotal = round((float) DB::table('supplier_credits')
            ->where('purchase_payable_id', $payableId)
            ->sum('amount'), 2);
        $actualPaid = round($cashPaid + $appliedCredits, 2);

        $newTotalAmount = max(round($receiptTotal - $returnedTotal, 2), 0.0);
        $newPaidAmount = min($actualPaid, $newTotalAmount);
        $newOutstandingAmount = max(round($newTotalAmount - $actualPaid, 2), 0.0);
        $newCreditTotal = max(round($actualPaid - $newTotalAmount, 2), 0.0);
        $newStatus = $newTotalAmount <= 0
            ? 'adjusted'
            : ($newOutstandingAmount <= 0
                ? 'paid'
                : ($newPaidAmount > 0 ? 'partial_paid' : 'pending'));

        DB::table('purchase_payables')
            ->where('id', $payableId)
            ->update([
                'total_amount' => $newTotalAmount,
                'paid_amount' => $newPaidAmount,
                'outstanding_amount' => $newOutstandingAmount,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        $creditDelta = max(round($newCreditTotal - $existingCreditTotal, 2), 0.0);

        if ($creditDelta > 0) {
            DB::table('supplier_credits')->insert([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'purchase_payable_id' => $payableId,
                'purchase_return_id' => $returnId,
                'amount' => $creditDelta,
                'remaining_amount' => $creditDelta,
                'status' => 'available',
                'reference' => $reference.'-CREDIT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $creditDelta;
    }
}
