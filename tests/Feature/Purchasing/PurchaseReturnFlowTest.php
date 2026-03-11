<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_register_partial_purchase_return_and_adjust_stock_and_payable(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20181818181', 'Proveedor Retorno Parcial');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $receiptResponse = $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 12,
                    'unit_cost' => 2.00,
                ]],
            ])
            ->assertOk();

        $receiptId = $receiptResponse->json('data.id');
        $receiptItemId = DB::table('purchase_receipt_items')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');
        $payableId = DB::table('purchase_payables')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');

        $returnResponse = $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/receipts/{$receiptId}/returns", [
                'reason' => 'Unidades dañadas',
                'items' => [[
                    'purchase_receipt_item_id' => $receiptItemId,
                    'quantity' => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 10)
            ->assertJsonPath('data.supplier_credit_amount', 0)
            ->assertJsonPath('data.items.0.purchase_receipt_item_id', $receiptItemId);

        $returnReference = $returnResponse->json('data.reference');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        $this->assertDatabaseHas('purchase_returns', [
            'tenant_id' => 10,
            'purchase_receipt_id' => $receiptId,
            'purchase_payable_id' => $payableId,
            'status' => 'processed',
            'total_amount' => 10.00,
        ]);

        $this->assertDatabaseHas('purchase_return_items', [
            'purchase_receipt_item_id' => $receiptItemId,
            'lot_id' => $lotId,
            'quantity' => 5,
            'line_total' => 10.00,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 67,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => 10,
            'lot_id' => $lotId,
            'type' => 'purchase_return',
            'quantity' => -5,
            'reference' => $returnReference,
        ]);

        $this->assertDatabaseHas('purchase_receipts', [
            'id' => $receiptId,
            'status' => 'partially_returned',
        ]);

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'total_amount' => 14.00,
            'paid_amount' => 0.00,
            'outstanding_amount' => 14.00,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'last_cost' => 2.00,
            'average_cost' => 2.00,
        ]);
    }

    public function test_full_purchase_return_can_create_supplier_credit_when_receipt_was_already_paid(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $supplierId = $this->seedSupplier(10, '20191919191', 'Proveedor Crédito');

        $receiptResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 10,
                    'unit_cost' => 3.00,
                ]],
            ])
            ->assertOk();

        $receiptId = $receiptResponse->json('data.id');
        $payableId = DB::table('purchase_payables')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/payables/{$payableId}/payments", [
                'amount' => 30,
                'payment_method' => 'cash',
                'reference' => 'PAY-RETURN-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $returnResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/receipts/{$receiptId}/returns", [
                'reason' => 'Devolución completa',
            ])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 30)
            ->assertJsonPath('data.supplier_credit_amount', 30);

        $returnId = $returnResponse->json('data.id');

        $this->assertDatabaseHas('purchase_receipts', [
            'id' => $receiptId,
            'status' => 'returned',
        ]);

        $this->assertDatabaseHas('purchase_payables', [
            'id' => $payableId,
            'total_amount' => 0.00,
            'paid_amount' => 0.00,
            'outstanding_amount' => 0.00,
            'status' => 'adjusted',
        ]);

        $this->assertDatabaseHas('supplier_credits', [
            'tenant_id' => 10,
            'supplier_id' => $supplierId,
            'purchase_payable_id' => $payableId,
            'purchase_return_id' => $returnId,
            'amount' => 30.00,
            'remaining_amount' => 30.00,
            'status' => 'available',
        ]);
    }

    public function test_rejects_purchase_return_when_quantity_exceeds_remaining_or_stock(): void
    {
        $this->seedBaseCatalog();
        $warehouseUser = $this->seedUserWithRole(10, 'ALMACENERO');
        $supplierId = $this->seedSupplier(10, '20121212121', 'Proveedor Validaciones');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        $receiptResponse = $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 6,
                    'unit_cost' => 2.50,
                ]],
            ])
            ->assertOk();

        $receiptId = $receiptResponse->json('data.id');
        $receiptItemId = DB::table('purchase_receipt_items')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/receipts/{$receiptId}/returns", [
                'reason' => 'Primera devolución',
                'items' => [[
                    'purchase_receipt_item_id' => $receiptItemId,
                    'quantity' => 4,
                ]],
            ])
            ->assertOk();

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/receipts/{$receiptId}/returns", [
                'reason' => 'Exceso por remanente',
                'items' => [
                    [
                        'purchase_receipt_item_id' => $receiptItemId,
                        'quantity' => 2,
                    ],
                    [
                        'purchase_receipt_item_id' => $receiptItemId,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertStatus(422);

        DB::table('lots')
            ->where('id', $lotId)
            ->update([
                'stock_quantity' => 1,
                'updated_at' => now(),
            ]);

        $this->actingAs($warehouseUser)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/purchases/receipts/{$receiptId}/returns", [
                'reason' => 'Sin stock suficiente',
                'items' => [[
                    'purchase_receipt_item_id' => $receiptItemId,
                    'quantity' => 2,
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_lists_and_reads_purchase_returns_for_current_tenant_only(): void
    {
        $this->seedBaseCatalog();
        $warehouse10 = $this->seedUserWithRole(10, 'ALMACENERO');
        $warehouse20 = $this->seedUserWithRole(20, 'ALMACENERO');
        $supplier10 = $this->seedSupplier(10, '20131313131', 'Proveedor 10');
        $supplier20 = $this->seedSupplier(20, '20232323232', 'Proveedor 20');
        $lot10 = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $lot20 = DB::table('lots')->where('tenant_id', 20)->where('code', 'L-AMOX-001')->value('id');

        $receiptId10 = $this->seedReceiptWithItem(10, $warehouse10->id, $supplier10, $lot10, 'PUR-RET-10', 4, 2.00);
        $receiptId20 = $this->seedReceiptWithItem(20, $warehouse20->id, $supplier20, $lot20, 'PUR-RET-20', 5, 1.50);

        $returnId10 = $this->seedReturn(10, $warehouse10->id, $supplier10, $receiptId10, 'PRT-RET-10', 'Return 10', 4.00);
        $returnId20 = $this->seedReturn(20, $warehouse20->id, $supplier20, $receiptId20, 'PRT-RET-20', 'Return 20', 7.50);

        $this->actingAs($warehouse10)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/purchases/returns')
            ->assertOk()
            ->assertJsonFragment([
                'reference' => 'PRT-RET-10',
            ])
            ->assertJsonMissing([
                'reference' => 'PRT-RET-20',
            ]);

        $this->actingAs($warehouse10)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/purchases/returns/{$returnId10}")
            ->assertOk()
            ->assertJsonPath('data.reference', 'PRT-RET-10')
            ->assertJsonPath('data.supplier.name', 'Proveedor 10');

        $this->actingAs($warehouse20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/purchases/returns/{$returnId10}")
            ->assertStatus(404);

        $this->assertNotSame($returnId10, $returnId20);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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

    private function seedReceiptWithItem(
        int $tenantId,
        int $userId,
        int $supplierId,
        int $lotId,
        string $reference,
        int $quantity,
        float $unitCost
    ): int {
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');
        $lineTotal = round($quantity * $unitCost, 2);

        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $lineTotal,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_receipt_items')->insert([
            'purchase_receipt_id' => $receiptId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_payables')->insert([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => $lineTotal,
            'paid_amount' => 0,
            'outstanding_amount' => $lineTotal,
            'status' => 'pending',
            'due_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $receiptId;
    }

    private function seedReturn(
        int $tenantId,
        int $userId,
        int $supplierId,
        int $receiptId,
        string $reference,
        string $reason,
        float $totalAmount
    ): int {
        $receiptItemId = DB::table('purchase_receipt_items')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');
        $lotId = DB::table('purchase_receipt_items')
            ->where('id', $receiptItemId)
            ->value('lot_id');
        $productId = DB::table('purchase_receipt_items')
            ->where('id', $receiptItemId)
            ->value('product_id');
        $quantity = (int) DB::table('purchase_receipt_items')
            ->where('id', $receiptItemId)
            ->value('quantity');
        $unitCost = (float) DB::table('purchase_receipt_items')
            ->where('id', $receiptItemId)
            ->value('unit_cost');
        $payableId = DB::table('purchase_payables')
            ->where('purchase_receipt_id', $receiptId)
            ->value('id');

        $returnId = DB::table('purchase_returns')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'purchase_payable_id' => $payableId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'processed',
            'reason' => $reason,
            'total_amount' => $totalAmount,
            'returned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_return_items')->insert([
            'purchase_return_id' => $returnId,
            'purchase_receipt_item_id' => $receiptItemId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $totalAmount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $returnId;
    }
}
