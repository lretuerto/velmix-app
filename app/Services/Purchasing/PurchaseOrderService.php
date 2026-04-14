<?php

namespace App\Services\Purchasing;

use App\Support\ReferenceCode;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseOrderService
{
    public function createFromReplenishment(int $tenantId, int $userId, int $supplierId, array $items): array
    {
        $normalizedItems = array_map(function (array $item) {
            $quantity = isset($item['order_quantity'])
                ? (int) $item['order_quantity']
                : (int) ($item['suggested_order_quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new HttpException(422, 'Replenishment order quantity must be greater than zero.');
            }

            return [
                'product_id' => (int) $item['product_id'],
                'ordered_quantity' => $quantity,
                'unit_cost' => (float) $item['unit_cost'],
            ];
        }, $items);

        return $this->create($tenantId, $userId, $supplierId, $normalizedItems);
    }

    public function create(int $tenantId, int $userId, int $supplierId, array $items): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($items === []) {
            throw new HttpException(422, 'Purchase order items are required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $supplierId, $items) {
            $supplier = DB::table('suppliers')
                ->where('tenant_id', $tenantId)
                ->where('id', $supplierId)
                ->first(['id', 'name']);

            if ($supplier === null) {
                throw new HttpException(404, 'Supplier not found.');
            }

            $orderId = DB::table('purchase_orders')->insertGetId([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'user_id' => $userId,
                'reference' => ReferenceCode::temporary('PO'),
                'status' => 'open',
                'total_amount' => 0,
                'ordered_at' => now(),
                'received_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $reference = ReferenceCode::fromId('PO', $orderId);
            $totalAmount = 0.0;
            $responseItems = [];

            foreach ($items as $item) {
                $product = DB::table('products')
                    ->where('tenant_id', $tenantId)
                    ->where('id', (int) $item['product_id'])
                    ->first(['id', 'sku', 'name']);

                if ($product === null) {
                    throw new HttpException(404, 'Product not found.');
                }

                $orderedQuantity = (int) $item['ordered_quantity'];
                $unitCost = (float) $item['unit_cost'];
                $lineTotal = round($orderedQuantity * $unitCost, 2);
                $totalAmount += $lineTotal;

                DB::table('purchase_order_items')->insert([
                    'purchase_order_id' => $orderId,
                    'product_id' => $product->id,
                    'ordered_quantity' => $orderedQuantity,
                    'received_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $responseItems[] = [
                    'product_id' => $product->id,
                    'product_sku' => $product->sku,
                    'ordered_quantity' => $orderedQuantity,
                    'received_quantity' => 0,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ];
            }

            DB::table('purchase_orders')
                ->where('id', $orderId)
                ->update([
                    'reference' => $reference,
                    'total_amount' => round($totalAmount, 2),
                    'updated_at' => now(),
                ]);

            return [
                'id' => $orderId,
                'reference' => $reference,
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier->name,
                'status' => 'open',
                'total_amount' => round($totalAmount, 2),
                'items' => $responseItems,
            ];
        });
    }

    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('purchase_orders')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.tenant_id', $tenantId)
            ->orderByDesc('purchase_orders.id')
            ->get([
                'purchase_orders.id',
                'purchase_orders.reference',
                'purchase_orders.status',
                'purchase_orders.total_amount',
                'purchase_orders.ordered_at',
                'purchase_orders.received_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
            ])
            ->map(fn (object $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status,
                'total_amount' => (float) $order->total_amount,
                'ordered_at' => $order->ordered_at,
                'received_at' => $order->received_at,
                'supplier' => [
                    'tax_id' => $order->supplier_tax_id,
                    'name' => $order->supplier_name,
                ],
            ])
            ->all();
    }

    public function detail(int $tenantId, int $orderId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $order = DB::table('purchase_orders')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.tenant_id', $tenantId)
            ->where('purchase_orders.id', $orderId)
            ->first([
                'purchase_orders.id',
                'purchase_orders.reference',
                'purchase_orders.status',
                'purchase_orders.total_amount',
                'purchase_orders.ordered_at',
                'purchase_orders.received_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
            ]);

        if ($order === null) {
            throw new HttpException(404, 'Purchase order not found.');
        }

        $items = DB::table('purchase_order_items')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->where('purchase_order_items.purchase_order_id', $orderId)
            ->orderBy('purchase_order_items.id')
            ->get([
                'purchase_order_items.id',
                'purchase_order_items.ordered_quantity',
                'purchase_order_items.received_quantity',
                'purchase_order_items.unit_cost',
                'purchase_order_items.line_total',
                'products.sku as product_sku',
                'products.name as product_name',
            ])
            ->map(fn (object $item) => [
                'id' => $item->id,
                'ordered_quantity' => (int) $item->ordered_quantity,
                'received_quantity' => (int) $item->received_quantity,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
            ])
            ->all();

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status,
            'total_amount' => (float) $order->total_amount,
            'ordered_at' => $order->ordered_at,
            'received_at' => $order->received_at,
            'supplier' => [
                'tax_id' => $order->supplier_tax_id,
                'name' => $order->supplier_name,
            ],
            'items' => $items,
        ];
    }
}
