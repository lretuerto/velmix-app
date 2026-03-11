<?php

namespace App\Services\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosSaleService
{
    public function execute(int $tenantId, int $userId, array $items, string $paymentMethod = 'cash'): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($items === []) {
            throw new HttpException(422, 'At least one sale item is required.');
        }

        if (! in_array($paymentMethod, ['cash', 'card', 'transfer'], true)) {
            throw new HttpException(422, 'Payment method is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $items, $paymentMethod) {
            $reference = 'SALE-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $resolvedItems = collect($items)
                ->map(fn (array $item) => $this->resolveItem($tenantId, $item));

            $totalAmount = round($resolvedItems->sum('line_total'), 2);
            $grossCost = round($resolvedItems->sum('cost_amount'), 2);
            $grossMargin = round($totalAmount - $grossCost, 2);

            $saleId = DB::table('sales')->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'reference' => $reference,
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'total_amount' => $totalAmount,
                'gross_cost' => $grossCost,
                'gross_margin' => $grossMargin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($resolvedItems as $item) {
                foreach ($item['allocations'] as $allocation) {
                    $costAmount = round($allocation['quantity'] * $item['unit_cost_snapshot'], 2);
                    $lineTotal = round($allocation['quantity'] * $item['unit_price'], 2);

                    DB::table('sale_items')->insert([
                        'sale_id' => $saleId,
                        'lot_id' => $allocation['lot_id'],
                        'product_id' => $item['product_id'],
                        'quantity' => $allocation['quantity'],
                        'unit_price' => $item['unit_price'],
                        'unit_cost_snapshot' => $item['unit_cost_snapshot'],
                        'line_total' => $lineTotal,
                        'cost_amount' => $costAmount,
                        'gross_margin' => round($lineTotal - $costAmount, 2),
                        'prescription_code' => $item['prescription_code'],
                        'approval_code' => $item['approval_code'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('lots')
                        ->where('id', $allocation['lot_id'])
                        ->update([
                            'stock_quantity' => $allocation['remaining_stock'],
                            'updated_at' => now(),
                        ]);

                    DB::table('stock_movements')->insert([
                        'tenant_id' => $tenantId,
                        'lot_id' => $allocation['lot_id'],
                        'product_id' => $item['product_id'],
                        'sale_id' => $saleId,
                        'type' => 'sale',
                        'quantity' => -$allocation['quantity'],
                        'reference' => $reference,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($item['approval_id'] !== null) {
                    DB::table('sale_approvals')
                        ->where('id', $item['approval_id'])
                        ->update([
                            'status' => 'consumed',
                            'consumed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }

            return [
                'sale_id' => $saleId,
                'reference' => $reference,
                'payment_method' => $paymentMethod,
                'total_amount' => $totalAmount,
                'gross_cost' => $grossCost,
                'gross_margin' => $grossMargin,
                'items' => $resolvedItems->map(fn (array $item) => [
                    'product_id' => $item['product_id'],
                    'product_sku' => $item['product_sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost_snapshot' => $item['unit_cost_snapshot'],
                    'line_total' => $item['line_total'],
                    'cost_amount' => $item['cost_amount'],
                    'gross_margin' => $item['gross_margin'],
                    'prescription_code' => $item['prescription_code'],
                    'approval_code' => $item['approval_code'],
                    'allocations' => array_map(fn (array $allocation) => [
                        'lot_id' => $allocation['lot_id'],
                        'lot_code' => $allocation['lot_code'],
                        'quantity' => $allocation['quantity'],
                        'remaining_stock' => $allocation['remaining_stock'],
                    ], $item['allocations']),
                ])->values()->all(),
            ];
        });
    }

    private function resolveItem(int $tenantId, array $item): array
    {
        $quantity = (int) ($item['quantity'] ?? 0);
        $unitPrice = (float) ($item['unit_price'] ?? -1);
        $prescriptionCode = $item['prescription_code'] ?? null;
        $approvalCode = $item['approval_code'] ?? null;

        if ($quantity <= 0 || $unitPrice < 0) {
            throw new HttpException(422, 'Quantity and unit price must be valid.');
        }

        if (array_key_exists('lot_id', $item) && $item['lot_id'] !== null) {
            return $this->resolveDirectLotItem($tenantId, (int) $item['lot_id'], $quantity, $unitPrice, $prescriptionCode, $approvalCode);
        }

        if (array_key_exists('product_id', $item) && $item['product_id'] !== null) {
            return $this->resolveFifoProductItem($tenantId, (int) $item['product_id'], $quantity, $unitPrice, $prescriptionCode, $approvalCode);
        }

        throw new HttpException(422, 'Each item must include lot_id or product_id.');
    }

    private function resolveDirectLotItem(
        int $tenantId,
        int $lotId,
        int $quantity,
        float $unitPrice,
        ?string $prescriptionCode,
        ?string $approvalCode
    ): array {
        $lot = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.id', $lotId)
            ->where('lots.tenant_id', $tenantId)
            ->lockForUpdate()
            ->first([
                'lots.id',
                'lots.product_id',
                'lots.code',
                'lots.stock_quantity',
                'lots.status',
                'lots.expires_at',
                'products.sku',
                'products.is_controlled',
                'products.average_cost',
            ]);

        if ($lot === null) {
            throw new HttpException(404, 'Lot not found.');
        }

        if ($lot->stock_quantity < $quantity) {
            throw new HttpException(422, 'Insufficient stock for lot.');
        }

        $this->assertLotAvailableForSale((string) $lot->status, (string) $lot->expires_at);

        $approval = $this->resolveControlledAuthorization(
            $tenantId,
            (int) $lot->product_id,
            (bool) $lot->is_controlled,
            $prescriptionCode,
            $approvalCode,
        );

        return [
            'product_id' => $lot->product_id,
            'product_sku' => $lot->sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost_snapshot' => round((float) $lot->average_cost, 2),
            'line_total' => round($quantity * $unitPrice, 2),
            'cost_amount' => round($quantity * (float) $lot->average_cost, 2),
            'gross_margin' => round(($quantity * $unitPrice) - ($quantity * (float) $lot->average_cost), 2),
            'prescription_code' => $prescriptionCode,
            'approval_code' => $approval['approval_code'],
            'approval_id' => $approval['approval_id'],
            'allocations' => [[
                'lot_id' => $lot->id,
                'lot_code' => $lot->code,
                'quantity' => $quantity,
                'remaining_stock' => $lot->stock_quantity - $quantity,
            ]],
        ];
    }

    private function resolveFifoProductItem(
        int $tenantId,
        int $productId,
        int $quantity,
        float $unitPrice,
        ?string $prescriptionCode,
        ?string $approvalCode
    ): array {
        $product = DB::table('products')
            ->where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->first(['id', 'sku', 'is_controlled', 'average_cost']);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        $approval = $this->resolveControlledAuthorization(
            $tenantId,
            $productId,
            (bool) $product->is_controlled,
            $prescriptionCode,
            $approvalCode,
        );

        $lots = DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->where('stock_quantity', '>', 0)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'stock_quantity']);

        return [
            'product_id' => $product->id,
            'product_sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost_snapshot' => round((float) $product->average_cost, 2),
            'line_total' => round($quantity * $unitPrice, 2),
            'cost_amount' => round($quantity * (float) $product->average_cost, 2),
            'gross_margin' => round(($quantity * $unitPrice) - ($quantity * (float) $product->average_cost), 2),
            'prescription_code' => $prescriptionCode,
            'approval_code' => $approval['approval_code'],
            'approval_id' => $approval['approval_id'],
            'allocations' => $this->allocateFifoLots($lots, $quantity),
        ];
    }

    private function resolveControlledAuthorization(
        int $tenantId,
        int $productId,
        bool $isControlled,
        ?string $prescriptionCode,
        ?string $approvalCode
    ): array {
        if (! $isControlled) {
            return [
                'approval_id' => null,
                'approval_code' => null,
            ];
        }

        if ($prescriptionCode !== null && trim($prescriptionCode) !== '') {
            return [
                'approval_id' => null,
                'approval_code' => null,
            ];
        }

        if ($approvalCode === null || trim($approvalCode) === '') {
            throw new HttpException(422, 'Controlled product requires prescription_code or approval_code.');
        }

        $approval = DB::table('sale_approvals')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('code', $approvalCode)
            ->where('status', 'approved')
            ->lockForUpdate()
            ->first(['id', 'code']);

        if ($approval === null) {
            throw new HttpException(422, 'Approval code is invalid or already consumed.');
        }

        return [
            'approval_id' => $approval->id,
            'approval_code' => $approval->code,
        ];
    }

    private function allocateFifoLots(Collection $lots, int $quantity): array
    {
        $remaining = $quantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($remaining, (int) $lot->stock_quantity);

            if ($taken <= 0) {
                continue;
            }

            $remaining -= $taken;
            $allocations[] = [
                'lot_id' => $lot->id,
                'lot_code' => $lot->code,
                'quantity' => $taken,
                'remaining_stock' => (int) $lot->stock_quantity - $taken,
            ];
        }

        if ($remaining > 0) {
            throw new HttpException(422, 'Insufficient stock for product.');
        }

        return $allocations;
    }

    private function assertLotAvailableForSale(string $status, string $expiresAt): void
    {
        if ($status !== 'available') {
            throw new HttpException(422, 'Lot is not available for sale.');
        }

        if ($expiresAt < now()->toDateString()) {
            throw new HttpException(422, 'Lot is expired.');
        }
    }
}
