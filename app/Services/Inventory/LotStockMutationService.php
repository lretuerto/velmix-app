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

        return $this->currentStockOrFail((int) $lot->id, $notFoundMessage);
    }

    public function decrementLockedLot(
        object $lot,
        int $quantity,
        string $insufficientMessage = 'Stock cannot become negative.',
        string $notFoundMessage = 'Lot not found.'
    ): int {
        $this->assertPositiveQuantity($quantity);

        $affected = DB::table('lots')
            ->where('id', $lot->id)
            ->where('stock_quantity', '>=', $quantity)
            ->decrement('stock_quantity', $quantity, [
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            return $this->currentStockOrFail((int) $lot->id, $notFoundMessage);
        }

        $exists = DB::table('lots')
            ->where('id', $lot->id)
            ->exists();

        if (! $exists) {
            throw new HttpException(404, $notFoundMessage);
        }

        throw new HttpException(422, $insufficientMessage);
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

    private function currentStockOrFail(int $lotId, string $notFoundMessage): int
    {
        $currentStock = DB::table('lots')
            ->where('id', $lotId)
            ->value('stock_quantity');

        if ($currentStock === null) {
            throw new HttpException(404, $notFoundMessage);
        }

        return (int) $currentStock;
    }
}
