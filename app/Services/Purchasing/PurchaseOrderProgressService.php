<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderProgressService
{
    public function addReceivedQuantity(int $purchaseOrderId, int $productId, int $quantity): array
    {
        $this->assertPositiveQuantity($quantity);

        $orderItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first(['id', 'ordered_quantity', 'received_quantity']);

        if ($orderItem === null) {
            throw new HttpException(422, 'Received product is not part of the purchase order.');
        }

        $newReceivedQuantity = (int) $orderItem->received_quantity + $quantity;

        if ($newReceivedQuantity > (int) $orderItem->ordered_quantity) {
            throw new HttpException(422, 'Received quantity exceeds ordered quantity.');
        }

        DB::table('purchase_order_items')
            ->where('id', $orderItem->id)
            ->update([
                'received_quantity' => $newReceivedQuantity,
                'updated_at' => now(),
            ]);

        return [
            'ordered_quantity' => (int) $orderItem->ordered_quantity,
            'received_quantity' => $newReceivedQuantity,
        ];
    }

    public function subtractReceivedQuantity(int $purchaseOrderId, int $productId, int $quantity): array
    {
        $this->assertPositiveQuantity($quantity);

        $orderItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first(['id', 'ordered_quantity', 'received_quantity']);

        if ($orderItem === null) {
            throw new HttpException(422, 'Purchase order progress is inconsistent for returned product.');
        }

        if ((int) $orderItem->received_quantity < $quantity) {
            throw new HttpException(422, 'Purchase return would make purchase order progress negative.');
        }

        $newReceivedQuantity = (int) $orderItem->received_quantity - $quantity;

        DB::table('purchase_order_items')
            ->where('id', $orderItem->id)
            ->update([
                'received_quantity' => $newReceivedQuantity,
                'updated_at' => now(),
            ]);

        return [
            'ordered_quantity' => (int) $orderItem->ordered_quantity,
            'received_quantity' => $newReceivedQuantity,
        ];
    }

    public function refreshStatus(int $purchaseOrderId): string
    {
        $order = DB::table('purchase_orders')
            ->where('id', $purchaseOrderId)
            ->lockForUpdate()
            ->first(['id']);

        if ($order === null) {
            throw new HttpException(404, 'Purchase order not found.');
        }

        $totals = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->selectRaw('SUM(ordered_quantity) as ordered_total, SUM(received_quantity) as received_total')
            ->first();

        $orderedTotal = (int) ($totals->ordered_total ?? 0);
        $receivedTotal = (int) ($totals->received_total ?? 0);
        $status = $receivedTotal <= 0
            ? 'open'
            : ($receivedTotal < $orderedTotal ? 'partially_received' : 'received');

        DB::table('purchase_orders')
            ->where('id', $purchaseOrderId)
            ->update([
                'status' => $status,
                'received_at' => $status === 'received' ? now() : null,
                'updated_at' => now(),
            ]);

        return $status;
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new HttpException(422, 'Quantity must be greater than zero.');
        }
    }
}
