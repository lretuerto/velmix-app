<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Services\Purchasing\PurchaseOrderProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseOrderProgressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_received_quantity_uses_current_database_state(): void
    {
        $this->seedBaseCatalog();

        $supplierId = $this->seedSupplier(10, '20156565656', 'Proveedor Progreso');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $orderId = $this->seedOrder(10, $supplierId, $productId, 10, 4);

        DB::table('purchase_order_items')
            ->where('purchase_order_id', $orderId)
            ->where('product_id', $productId)
            ->update([
                'received_quantity' => 6,
                'updated_at' => now(),
            ]);

        $result = app(PurchaseOrderProgressService::class)->addReceivedQuantity($orderId, $productId, 2);

        $this->assertSame(10, $result['ordered_quantity']);
        $this->assertSame(8, $result['received_quantity']);
        $this->assertSame(8, (int) DB::table('purchase_order_items')
            ->where('purchase_order_id', $orderId)
            ->where('product_id', $productId)
            ->value('received_quantity'));
    }

    public function test_subtract_received_quantity_rejects_negative_progress(): void
    {
        $this->seedBaseCatalog();

        $supplierId = $this->seedSupplier(10, '20157575757', 'Proveedor Progreso Negativo');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $orderId = $this->seedOrder(10, $supplierId, $productId, 10, 1);

        try {
            app(PurchaseOrderProgressService::class)->subtractReceivedQuantity($orderId, $productId, 2);
            $this->fail('Expected purchase order progress subtraction to reject negative received quantity.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Purchase return would make purchase order progress negative.', $exception->getMessage());
        }

        $this->assertSame(1, (int) DB::table('purchase_order_items')
            ->where('purchase_order_id', $orderId)
            ->where('product_id', $productId)
            ->value('received_quantity'));
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);
    }

    private function seedSupplier(int $tenantId, string $taxId, string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrder(int $tenantId, int $supplierId, int $productId, int $orderedQuantity, int $receivedQuantity): int
    {
        $userId = User::factory()->create()->id;

        $orderId = DB::table('purchase_orders')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'reference' => 'PO-TEST-'.$orderedQuantity.'-'.$receivedQuantity,
            'status' => 'partially_received',
            'total_amount' => 10,
            'ordered_at' => now(),
            'received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $orderId,
            'product_id' => $productId,
            'ordered_quantity' => $orderedQuantity,
            'received_quantity' => $receivedQuantity,
            'unit_cost' => 1,
            'line_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }
}
