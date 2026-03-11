<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchaseReceiptService
{
    public function receive(int $tenantId, int $userId, int $supplierId, array $items): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($items === []) {
            throw new HttpException(422, 'Purchase receipt items are required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $supplierId, $items) {
            $supplier = DB::table('suppliers')
                ->where('tenant_id', $tenantId)
                ->where('id', $supplierId)
                ->first(['id', 'name']);

            if ($supplier === null) {
                throw new HttpException(404, 'Supplier not found.');
            }

            $receiptId = DB::table('purchase_receipts')->insertGetId([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
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

            foreach ($items as $item) {
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

                $quantity = (int) $item['quantity'];
                $unitCost = (float) $item['unit_cost'];
                $lineTotal = round($quantity * $unitCost, 2);
                $resultingStock = $lot->stock_quantity + $quantity;
                $totalAmount += $lineTotal;

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

                $lineItems[] = [
                    'lot_id' => $lot->id,
                    'lot_code' => $lot->code,
                    'product_sku' => $lot->product_sku,
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

            return [
                'id' => $receiptId,
                'reference' => $reference,
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier->name,
                'status' => 'received',
                'total_amount' => round($totalAmount, 2),
                'items' => $lineItems,
            ];
        });
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
                'purchase_receipts.reference',
                'purchase_receipts.status',
                'purchase_receipts.total_amount',
                'purchase_receipts.received_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
            ])
            ->map(fn (object $receipt) => [
                'id' => $receipt->id,
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
}
