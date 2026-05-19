<?php

namespace Tests\Feature\Concurrency;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('concurrency')]
class MysqlConcurrencySmokeTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $secondaryConnectionName = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL concurrency smoke tests only run on the MySQL CI lane.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->secondaryConnectionName !== null) {
            DB::disconnect($this->secondaryConnectionName);
            config()->offsetUnset('database.connections.'.$this->secondaryConnectionName);
        }

        parent::tearDown();
    }

    public function test_mysql_row_lock_blocks_competing_lot_stock_update(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $lotId = (int) DB::table('lots')
            ->where('tenant_id', 10)
            ->where('code', 'L-PARA-001')
            ->value('id');

        $primary = DB::connection();
        $secondary = $this->secondaryConnection();

        $primary->beginTransaction();

        try {
            $primary->table('lots')->where('id', $lotId)->lockForUpdate()->first();
            $secondary->statement('SET innodb_lock_wait_timeout = 1');

            $exception = null;

            try {
                $secondary->table('lots')
                    ->where('id', $lotId)
                    ->decrement('stock_quantity', 1, [
                        'updated_at' => now(),
                    ]);
            } catch (QueryException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception, 'Expected competing lot update to fail while the row is locked.');
            $this->assertStringContainsString('lock wait timeout', strtolower($exception->getMessage()));
        } finally {
            $primary->rollBack();
        }
    }

    public function test_mysql_row_lock_blocks_competing_purchase_order_progress_update(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $supplierId = $this->seedSupplier(10, '20175550001', 'Proveedor Lock OC');
        $productId = (int) DB::table('products')
            ->where('tenant_id', 10)
            ->where('sku', 'PARA-500')
            ->value('id');
        $userId = User::factory()->create()->id;

        $orderId = (int) DB::table('purchase_orders')->insertGetId([
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'reference' => 'PO-LOCK-000001',
            'status' => 'open',
            'total_amount' => 18,
            'ordered_at' => now(),
            'received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId,
            'product_id' => $productId,
            'ordered_quantity' => 10,
            'received_quantity' => 0,
            'unit_cost' => 1.80,
            'line_total' => 18,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $primary = DB::connection();
        $secondary = $this->secondaryConnection();

        $primary->beginTransaction();

        try {
            $primary->table('purchase_order_items')
                ->where('id', $itemId)
                ->lockForUpdate()
                ->first();
            $secondary->statement('SET innodb_lock_wait_timeout = 1');

            $exception = null;

            try {
                $secondary->table('purchase_order_items')
                    ->where('id', $itemId)
                    ->update([
                        'received_quantity' => 3,
                        'updated_at' => now(),
                    ]);
            } catch (QueryException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception, 'Expected competing purchase-order progress update to fail while the row is locked.');
            $this->assertStringContainsString('lock wait timeout', strtolower($exception->getMessage()));
        } finally {
            $primary->rollBack();
        }
    }

    public function test_mysql_unique_idempotency_scope_serializes_duplicate_reservations(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $primary = DB::connection();
        $secondary = $this->secondaryConnection();

        $primary->beginTransaction();

        try {
            $primary->table('idempotency_keys')->insert([
                'tenant_id' => 10,
                'method' => 'POST',
                'path' => '/billing/vouchers',
                'idempotency_key' => 'same-key',
                'request_hash' => hash('sha256', 'payload-a'),
                'status' => 'in_progress',
                'locked_until' => now()->addMinutes(5),
                'response_status' => null,
                'response_headers' => null,
                'response_body' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $secondary->statement('SET innodb_lock_wait_timeout = 1');

            $exception = null;

            try {
                $secondary->table('idempotency_keys')->insert([
                    'tenant_id' => 10,
                    'method' => 'POST',
                    'path' => '/billing/vouchers',
                    'idempotency_key' => 'same-key',
                    'request_hash' => hash('sha256', 'payload-b'),
                    'status' => 'in_progress',
                    'locked_until' => now()->addMinutes(5),
                    'response_status' => null,
                    'response_headers' => null,
                    'response_body' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception, 'Expected competing idempotency reservation to fail or wait under MySQL.');
            $this->assertTrue(
                str_contains(strtolower($exception->getMessage()), 'duplicate')
                || str_contains(strtolower($exception->getMessage()), 'lock wait timeout'),
                'Unexpected failure mode for competing idempotency reservation: '.$exception->getMessage(),
            );
        } finally {
            $primary->rollBack();
        }
    }

    private function secondaryConnection()
    {
        $this->secondaryConnectionName ??= 'mysql_concurrency_secondary';

        config([
            'database.connections.'.$this->secondaryConnectionName => array_merge(
                config('database.connections.mysql'),
                ['name' => $this->secondaryConnectionName],
            ),
        ]);

        DB::purge($this->secondaryConnectionName);

        return DB::connection($this->secondaryConnectionName);
    }

    private function seedSupplier(int $tenantId, string $taxId, string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
