<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LotControlService
{
    public function immobilize(int $tenantId, int $lotId, string $reason): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $lot = DB::table('lots')
            ->join('products', 'products.id', '=', 'lots.product_id')
            ->where('lots.id', $lotId)
            ->where('lots.tenant_id', $tenantId)
            ->first(['lots.id', 'lots.code', 'products.sku']);

        if ($lot === null) {
            throw new HttpException(404, 'Lot not found.');
        }

        DB::table('lots')
            ->where('id', $lotId)
            ->update([
                'status' => 'immobilized',
                'updated_at' => now(),
            ]);

        return [
            'lot_id' => $lotId,
            'lot_code' => $lot->code,
            'product_sku' => $lot->sku,
            'status' => 'immobilized',
            'reason' => $reason,
        ];
    }
}
