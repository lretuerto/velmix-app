<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleApprovalService
{
    public function create(int $tenantId, int $approvedByUserId, int $productId, string $reason): array
    {
        if ($tenantId <= 0 || $approvedByUserId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $product = DB::table('products')
            ->where('id', $productId)
            ->where('tenant_id', $tenantId)
            ->first(['id', 'sku', 'is_controlled']);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        if (! $product->is_controlled) {
            throw new HttpException(422, 'Approval is only required for controlled products.');
        }

        $code = 'APR-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        $approvalId = DB::table('sale_approvals')->insertGetId([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'approved_by_user_id' => $approvedByUserId,
            'code' => $code,
            'reason' => $reason,
            'status' => 'approved',
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $approvalId,
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'product_sku' => $product->sku,
            'code' => $code,
            'reason' => $reason,
            'status' => 'approved',
        ];
    }
}
