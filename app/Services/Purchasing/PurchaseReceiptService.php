<?php

namespace App\Services\Purchasing;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseReceiptService
{
    public function receive(int $tenantId, int $userId, int $supplierId, ?int $purchaseOrderId, array $items): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($items === []) {
            throw new HttpException(422, 'Purchase receipt items are required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $supplierId, $purchaseOrderId, $items) {
            $supplier = DB::table('suppliers')
                ->where('tenant_id', $tenantId)
                ->where('id', $supplierId)
                ->first(['id', 'name']);

            if ($supplier === null) {
                throw new HttpException(404, 'Supplier not found.');
            }

            $purchaseOrder = null;
            $orderedItemMap = [];

            if ($purchaseOrderId !== null) {
                $purchaseOrder = DB::table('purchase_orders')
                    ->where('tenant_id', $tenantId)
                    ->where('supplier_id', $supplierId)
                    ->where('id', $purchaseOrderId)
                    ->lockForUpdate()
                    ->first(['id', 'status']);

                if ($purchaseOrder === null) {
                    throw new HttpException(404, 'Purchase order not found.');
                }

                $orderedItemMap = DB::table('purchase_order_items')
                    ->where('purchase_order_id', $purchaseOrderId)
                    ->get(['id', 'product_id', 'ordered_quantity', 'received_quantity'])
                    ->keyBy('product_id')
                    ->all();
            }

            $receiptId = DB::table('purchase_receipts')->insertGetId([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'purchase_order_id' => $purchaseOrderId,
                'user_id' => $userId,
                'reference' => 'PENDING',
                'status' => 'received',
                'total_amount' => 0,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reference = 'PUR-'.str_pad((string) $receiptId, 6, '0', STR_PAD_LEFT);
            $totalAmount = 0.0;
            $lineItems = [];
            $receivedProductIds = [];

            foreach ($items as $item) {
                $lot = $this->resolveLot($tenantId, $item);

                $quantity = (int) $item['quantity'];
                $unitCost = (float) $item['unit_cost'];
                $lineTotal = round($quantity * $unitCost, 2);
                $resultingStock = $lot->stock_quantity + $quantity;
                $totalAmount += $lineTotal;

                if ($purchaseOrder !== null) {
                    $orderedItem = $orderedItemMap[$lot->product_id] ?? null;

                    if ($orderedItem === null) {
                        throw new HttpException(422, 'Received product is not part of the purchase order.');
                    }

                    $newReceivedQuantity = (int) $orderedItem->received_quantity + $quantity;

                    if ($newReceivedQuantity > (int) $orderedItem->ordered_quantity) {
                        throw new HttpException(422, 'Received quantity exceeds ordered quantity.');
                    }

                    DB::table('purchase_order_items')
                        ->where('id', $orderedItem->id)
                        ->update([
                            'received_quantity' => $newReceivedQuantity,
                            'updated_at' => now(),
                        ]);

                    $orderedItem->received_quantity = $newReceivedQuantity;
                    $orderedItemMap[$lot->product_id] = $orderedItem;
                }

                DB::table('lots')
                    ->where('id', $lot->id)
                    ->update([
                        'stock_quantity' => $resultingStock,
                        'updated_at' => now(),
                    ]);

                DB::table('purchase_receipt_items')->insert([
                    'purchase_receipt_id' => $receiptId,
                    'lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('stock_movements')->insert([
                    'tenant_id' => $tenantId,
                    'lot_id' => $lot->id,
                    'product_id' => $lot->product_id,
                    'sale_id' => null,
                    'type' => 'purchase_in',
                    'quantity' => $quantity,
                    'reference' => $reference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $receivedProductIds[$lot->product_id] = true;

                $lineItems[] = [
                    'lot_id' => $lot->id,
                    'lot_code' => $lot->code,
                    'product_sku' => $lot->product_sku,
                    'created_new_lot' => (bool) ($lot->created_new_lot ?? false),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'resulting_stock' => $resultingStock,
                ];
            }

            DB::table('purchase_receipts')
                ->where('id', $receiptId)
                ->update([
                    'reference' => $reference,
                    'total_amount' => round($totalAmount, 2),
                'updated_at' => now(),
            ]);

            foreach (array_keys($receivedProductIds) as $productId) {
                $this->recalculateProductCosts($tenantId, (int) $productId);
            }

            DB::table('purchase_payables')->insert([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'purchase_receipt_id' => $receiptId,
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => 0,
                'outstanding_amount' => round($totalAmount, 2),
                'status' => 'pending',
                'due_at' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payableId = (int) DB::table('purchase_payables')
                ->where('purchase_receipt_id', $receiptId)
                ->value('id');
            $creditApplication = null;

            $hasAvailableCredits = DB::table('supplier_credits')
                ->where('tenant_id', $tenantId)
                ->where('supplier_id', $supplierId)
                ->where('remaining_amount', '>', 0)
                ->exists();

            if ($hasAvailableCredits) {
                $creditApplication = app(SupplierCreditService::class)->applyAvailableCredits(
                    $tenantId,
                    $userId,
                    $payableId,
                    null,
                    'auto'
                );
            }

            if ($purchaseOrder !== null) {
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

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'purchasing',
                'purchasing.receipt.received',
                'purchase_receipt',
                $receiptId,
                'Recepcion de compra '.$reference.' registrada',
                [
                    'purchase_receipt_id' => $receiptId,
                    'reference' => $reference,
                    'supplier_id' => $supplierId,
                    'purchase_order_id' => $purchaseOrderId,
                    'item_count' => count($lineItems),
                    'total_amount' => round($totalAmount, 2),
                    'purchase_payable_id' => $payableId,
                    'supplier_credit_applied_amount' => (float) ($creditApplication['applied_amount'] ?? 0),
                ],
            );

            return [
                'id' => $receiptId,
                'reference' => $reference,
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'purchase_order_id' => $purchaseOrderId,
                'supplier_name' => $supplier->name,
                'status' => 'received',
                'total_amount' => round($totalAmount, 2),
                'purchase_payable_id' => $payableId,
                'supplier_credit_applied_amount' => (float) ($creditApplication['applied_amount'] ?? 0),
                'items' => $lineItems,
            ];
        });
    }

    public function detail(int $tenantId, int $receiptId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $receipt = DB::table('purchase_receipts')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_receipts.supplier_id')
            ->where('purchase_receipts.tenant_id', $tenantId)
            ->where('purchase_receipts.id', $receiptId)
            ->first([
                'purchase_receipts.id',
                'purchase_receipts.purchase_order_id',
                'purchase_receipts.reference',
                'purchase_receipts.status',
                'purchase_receipts.total_amount',
                'purchase_receipts.received_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
            ]);

        if ($receipt === null) {
            throw new HttpException(404, 'Purchase receipt not found.');
        }

        $items = DB::table('purchase_receipt_items')
            ->join('lots', 'lots.id', '=', 'purchase_receipt_items.lot_id')
            ->join('products', 'products.id', '=', 'purchase_receipt_items.product_id')
            ->where('purchase_receipt_items.purchase_receipt_id', $receiptId)
            ->orderBy('purchase_receipt_items.id')
            ->get([
                'purchase_receipt_items.id',
                'purchase_receipt_items.quantity',
                'purchase_receipt_items.unit_cost',
                'purchase_receipt_items.line_total',
                'lots.code as lot_code',
                'lots.expires_at as lot_expires_at',
                'products.sku as product_sku',
            ])
            ->map(fn (object $item) => [
                'id' => $item->id,
                'quantity' => (int) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
                'lot_code' => $item->lot_code,
                'lot_expires_at' => $item->lot_expires_at,
                'product_sku' => $item->product_sku,
            ])
            ->all();

        return [
            'id' => $receipt->id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'reference' => $receipt->reference,
            'status' => $receipt->status,
            'total_amount' => (float) $receipt->total_amount,
            'received_at' => $receipt->received_at,
            'supplier' => [
                'tax_id' => $receipt->supplier_tax_id,
                'name' => $receipt->supplier_name,
            ],
            'items' => $items,
        ];
    }

    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('purchase_receipts')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_receipts.supplier_id')
            ->where('purchase_receipts.tenant_id', $tenantId)
            ->orderByDesc('purchase_receipts.id')
            ->get([
                'purchase_receipts.id',
                'purchase_receipts.purchase_order_id',
                'purchase_receipts.reference',
                'purchase_receipts.status',
                'purchase_receipts.total_amount',
                'purchase_receipts.received_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
            ])
            ->map(fn (object $receipt) => [
                'id' => $receipt->id,
                'purchase_order_id' => $receipt->purchase_order_id,
                'reference' => $receipt->reference,
                'status' => $receipt->status,
                'total_amount' => (float) $receipt->total_amount,
                'received_at' => $receipt->received_at,
                'supplier' => [
                    'tax_id' => $receipt->supplier_tax_id,
                    'name' => $receipt->supplier_name,
                ],
            ])
            ->all();
    }

    private function resolveLot(int $tenantId, array $item): object
    {
        if (isset($item['lot_id']) && $item['lot_id'] !== null) {
            $lot = DB::table('lots')
                ->join('products', 'products.id', '=', 'lots.product_id')
                ->where('lots.tenant_id', $tenantId)
                ->where('lots.id', (int) $item['lot_id'])
                ->lockForUpdate()
                ->first([
                    'lots.id',
                    'lots.product_id',
                    'lots.stock_quantity',
                    'lots.code',
                    'products.sku as product_sku',
                ]);

            if ($lot === null) {
                throw new HttpException(404, 'Lot not found.');
            }

            $lot->created_new_lot = false;

            return $lot;
        }

        $productId = (int) ($item['product_id'] ?? 0);
        $lotCode = trim((string) ($item['lot_code'] ?? ''));
        $expiresAt = $item['expires_at'] ?? null;

        if ($productId <= 0 || $lotCode === '' || $expiresAt === null) {
            throw new HttpException(422, 'A new lot requires product_id, lot_code and expires_at.');
        }

        $product = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('id', $productId)
            ->first(['id', 'sku']);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        $lotExists = DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('code', $lotCode)
            ->exists();

        if ($lotExists) {
            throw new HttpException(422, 'Lot code already exists for tenant.');
        }

        $lotId = DB::table('lots')->insertGetId([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'code' => $lotCode,
            'expires_at' => $expiresAt,
            'stock_quantity' => 0,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) [
            'id' => $lotId,
            'product_id' => $productId,
            'stock_quantity' => 0,
            'code' => $lotCode,
            'product_sku' => $product->sku,
            'created_new_lot' => true,
        ];
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
}
