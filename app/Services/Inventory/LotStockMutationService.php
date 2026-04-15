<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LotStockMutationService
{
    public function incrementLockedLot(
        object $lot,
        int $quantity,
        string $notFoundMessage = 'Lot not found.'
    ): int {
        $this->assertPositiveQuantity($quantity);

        $affected = DB::table('lots')
            ->where('id', $lot->id)
            ->increment('stock_quantity', $quantity, [
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new HttpException(404, $notFoundMessage);
        }

        return (int) $lot->stock_quantity + $quantity;
    }

    public function decrementLockedLot(
        object $lot,
        int $quantity,
        string $insufficientMessage = 'Stock cannot become negative.',
        string $notFoundMessage = 'Lot not found.'
    ): int {
        $this->assertPositiveQuantity($quantity);

        $currentStock = (int) ($lot->stock_quantity ?? 0);

        if ($currentStock < $quantity) {
            throw new HttpException(422, $insufficientMessage);
        }

        $affected = DB::table('lots')
            ->where('id', $lot->id)
            ->decrement('stock_quantity', $quantity, [
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new HttpException(404, $notFoundMessage);
        }

        return $currentStock - $quantity;
    }

    public function decrementById(
        int $tenantId,
        int $lotId,
        int $quantity,
        string $notFoundMessage = 'Lot not found.',
        string $insufficientMessage = 'Stock cannot become negative.'
    ): int {
        $this->assertPositiveQuantity($quantity);

        $lot = DB::table('lots')
            ->where('tenant_id', $tenantId)
            ->where('id', $lotId)
            ->lockForUpdate()
            ->first(['id', 'stock_quantity']);

        if ($lot === null) {
            throw new HttpException(404, $notFoundMessage);
        }

        return $this->decrementLockedLot($lot, $quantity, $insufficientMessage, $notFoundMessage);
    }

    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new HttpException(422, 'Quantity must be greater than zero.');
        }
    }
}
