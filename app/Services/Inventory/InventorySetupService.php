<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventorySetupService
{
    public function createProduct(int $tenantId, string $sku, string $name, bool $isControlled): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $exists = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->where('sku', $sku)
            ->exists();

        if ($exists) {
            throw new HttpException(422, 'SKU already exists for tenant.');
        }

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => $name,
            'status' => 'active',
            'is_controlled' => $isControlled,
            'last_cost' => 0,
            'average_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $productId,
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => $name,
            'status' => 'active',
            'is_controlled' => $isControlled,
            'last_cost' => 0,
            'average_cost' => 0,
        ];
    }

    public function createLot(int $tenantId, int $productId, string $code, string $expiresAt, int $stockQuantity): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $product = DB::table('products')
            ->where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->first(['id', 'tenant_id', 'sku']);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        $exists = DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->exists();

        if ($exists) {
            throw new HttpException(422, 'Lot code already exists for tenant.');
        }

        $lotId = DB::table('lots')->insertGetId([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'code' => $code,
            'expires_at' => $expiresAt,
            'stock_quantity' => $stockQuantity,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $lotId,
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'product_sku' => $product->sku,
            'code' => $code,
            'expires_at' => $expiresAt,
            'stock_quantity' => $stockQuantity,
            'status' => 'available',
        ];
    }
}
