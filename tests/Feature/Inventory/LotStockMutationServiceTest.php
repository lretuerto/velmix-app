<?php

namespace Tests\Feature\Inventory;

use App\Services\Inventory\LotStockMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LotStockMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_increment_locked_lot_returns_actual_stock_when_snapshot_is_stale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $lot = DB::table('lots')
            ->where('tenant_id', 10)
            ->orderBy('id')
            ->first(['id', 'stock_quantity']);

        DB::table('lots')
            ->where('id', $lot->id)
            ->update([
                'stock_quantity' => 95,
                'updated_at' => now(),
            ]);

        $resultingStock = app(LotStockMutationService::class)->incrementLockedLot($lot, 5);

        $this->assertSame(100, $resultingStock);
        $this->assertSame(100, (int) DB::table('lots')->where('id', $lot->id)->value('stock_quantity'));
    }

    public function test_decrement_locked_lot_uses_database_guard_when_snapshot_is_stale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $lot = DB::table('lots')
            ->where('tenant_id', 10)
            ->orderBy('id')
            ->first(['id', 'stock_quantity']);

        DB::table('lots')
            ->where('id', $lot->id)
            ->update([
                'stock_quantity' => 2,
                'updated_at' => now(),
            ]);

        try {
            app(LotStockMutationService::class)->decrementLockedLot(
                $lot,
                3,
                'Insufficient stock for lot.',
                'Lot not found.'
            );

            $this->fail('Expected decrement to be rejected when database stock is already lower than the stale snapshot.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Insufficient stock for lot.', $exception->getMessage());
        }

        $this->assertSame(2, (int) DB::table('lots')->where('id', $lot->id)->value('stock_quantity'));
    }
}
