<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_executes_sale_and_decrements_lot_stock(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        DB::table('products')->where('id', $productId)->update([
            'average_cost' => 1.20,
            'last_cost' => 1.20,
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 5,
                'unit_price' => 3.50,
            ]);

        $saleId = $response->json('data.sale_id');
        $expectedReference = 'SALE-'.str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);

        $response->assertOk()
            ->assertJsonPath('data.sale_id', $saleId)
            ->assertJsonPath('data.reference', $expectedReference)
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.allocations.0.remaining_stock', 55)
            ->assertJsonPath('data.total_amount', 17.5)
            ->assertJsonPath('data.gross_cost', 6)
            ->assertJsonPath('data.gross_margin', 11.5);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 17.50,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => 10,
            'lot_id' => $lotId,
            'type' => 'sale',
            'quantity' => -5,
            'reference' => $expectedReference,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 55,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'lot_id' => $lotId,
            'unit_cost_snapshot' => 1.20,
            'cost_amount' => 6.00,
            'gross_margin' => 11.50,
        ]);

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'gross_cost' => 6.00,
            'gross_margin' => 11.50,
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'domain' => 'sales',
            'event_type' => 'sales.sale.completed',
            'aggregate_type' => 'sale',
            'aggregate_id' => $saleId,
        ]);
    }

    public function test_rejects_sale_when_lot_belongs_to_another_tenant(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $foreignLotId = DB::table('lots')->where('tenant_id', 20)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $foreignLotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(404);
    }

    public function test_rejects_sale_when_stock_is_insufficient(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 999,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    public function test_executes_fifo_sale_for_product_across_multiple_lots(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');
        $firstLotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $secondLotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-002')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 70,
                        'unit_price' => 3.50,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.lot_id', $firstLotId)
            ->assertJsonPath('data.items.0.allocations.0.quantity', 60)
            ->assertJsonPath('data.items.0.allocations.1.lot_id', $secondLotId)
            ->assertJsonPath('data.items.0.allocations.1.quantity', 10)
            ->assertJsonPath('data.total_amount', 245);

        $this->assertDatabaseHas('lots', [
            'id' => $firstLotId,
            'stock_quantity' => 0,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $secondLotId,
            'stock_quantity' => 50,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $firstLotId,
            'quantity' => -60,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $secondLotId,
            'quantity' => -10,
        ]);
    }

    public function test_reserves_stock_across_repeated_direct_lot_lines_in_same_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'stock_quantity' => 10,
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'lot_id' => $lotId,
                        'quantity' => 4,
                        'unit_price' => 3.50,
                    ],
                    [
                        'lot_id' => $lotId,
                        'quantity' => 3,
                        'unit_price' => 3.50,
                    ],
                ],
            ]);

        $saleId = (int) $response->json('data.sale_id');

        $response->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.remaining_stock', 6)
            ->assertJsonPath('data.items.1.allocations.0.remaining_stock', 3);

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 3,
        ]);

        $this->assertSame(7, (int) DB::table('sale_items')->where('sale_id', $saleId)->sum('quantity'));
        $this->assertSame(-7, (int) DB::table('stock_movements')->where('sale_id', $saleId)->sum('quantity'));
    }

    public function test_rejects_repeated_direct_lot_lines_when_combined_quantity_exceeds_stock(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'stock_quantity' => 10,
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'lot_id' => $lotId,
                        'quantity' => 7,
                        'unit_price' => 3.50,
                    ],
                    [
                        'lot_id' => $lotId,
                        'quantity' => 7,
                        'unit_price' => 3.50,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock for lot.');

        $this->assertDatabaseHas('lots', [
            'id' => $lotId,
            'stock_quantity' => 10,
        ]);

        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_reserves_fifo_stock_across_repeated_product_lines_in_same_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $firstLotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $secondLotId = (int) DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-002')->value('id');
        $productId = (int) DB::table('lots')->where('id', $firstLotId)->value('product_id');

        DB::table('lots')->where('id', $firstLotId)->update([
            'stock_quantity' => 5,
            'updated_at' => now(),
        ]);

        DB::table('lots')->where('id', $secondLotId)->update([
            'stock_quantity' => 4,
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 4,
                        'unit_price' => 3.50,
                    ],
                    [
                        'product_id' => $productId,
                        'quantity' => 3,
                        'unit_price' => 3.50,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.allocations.0.lot_id', $firstLotId)
            ->assertJsonPath('data.items.0.allocations.0.remaining_stock', 1)
            ->assertJsonPath('data.items.1.allocations.0.lot_id', $firstLotId)
            ->assertJsonPath('data.items.1.allocations.0.quantity', 1)
            ->assertJsonPath('data.items.1.allocations.0.remaining_stock', 0)
            ->assertJsonPath('data.items.1.allocations.1.lot_id', $secondLotId)
            ->assertJsonPath('data.items.1.allocations.1.remaining_stock', 2);

        $this->assertDatabaseHas('lots', [
            'id' => $firstLotId,
            'stock_quantity' => 0,
        ]);

        $this->assertDatabaseHas('lots', [
            'id' => $secondLotId,
            'stock_quantity' => 2,
        ]);
    }

    public function test_executes_multi_item_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'LORA-10',
            'name' => 'Loratadina 10mg',
            'status' => 'active',
            'is_controlled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondProductId = DB::table('products')->where('tenant_id', 10)->where('sku', 'LORA-10')->value('id');

        DB::table('lots')->insert([
            'tenant_id' => 10,
            'product_id' => $secondProductId,
            'code' => 'L-LORA-001',
            'expires_at' => '2027-11-30',
            'stock_quantity' => 40,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');
        $paracetamolProductId = DB::table('products')->where('tenant_id', 10)->where('sku', 'PARA-500')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [
                    [
                        'product_id' => $paracetamolProductId,
                        'quantity' => 5,
                        'unit_price' => 3.50,
                    ],
                    [
                        'product_id' => $secondProductId,
                        'quantity' => 2,
                        'unit_price' => 4.20,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.total_amount', 25.9);
    }

    public function test_persists_non_cash_payment_method_for_sale(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 2,
                'unit_price' => 3.50,
                'payment_method' => 'card',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'card');

        $this->assertDatabaseHas('sales', [
            'tenant_id' => 10,
            'payment_method' => 'card',
        ]);
    }

    public function test_credit_sale_requires_customer_and_creates_receivable(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '44556677',
            'name' => 'Cliente Credito POS',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 2,
                'unit_price' => 3.50,
                'payment_method' => 'credit',
                'customer_id' => $customerId,
                'due_at' => now()->addDays(10)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'credit')
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.receivable.status', 'pending');

        $this->assertDatabaseHas('sale_receivables', [
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'status' => 'pending',
            'outstanding_amount' => 7.00,
        ]);
    }

    public function test_credit_sale_uses_customer_credit_days_when_due_at_is_missing(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '77889900',
            'name' => 'Cliente Plazo',
            'credit_days' => 12,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 2,
                'unit_price' => 3.50,
                'payment_method' => 'credit',
                'customer_id' => $customerId,
            ])
            ->assertOk()
            ->assertJsonPath('data.receivable.status', 'pending');

        $this->assertDatabaseHas('sale_receivables', [
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'status' => 'pending',
        ]);
    }

    public function test_rejects_credit_sale_when_customer_credit_limit_is_exceeded(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '55667788',
            'name' => 'Cliente Tope',
            'credit_limit' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-LIMIT-BASE',
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 8,
            'gross_cost' => 3,
            'gross_margin' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivables')->insert([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 8,
            'paid_amount' => 0,
            'outstanding_amount' => 8,
            'status' => 'pending',
            'due_at' => now()->addDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
                'payment_method' => 'credit',
                'customer_id' => $customerId,
                'due_at' => now()->addDays(10)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_rejects_credit_sale_when_customer_has_overdue_receivable(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '66778899',
            'name' => 'Cliente Vencido',
            'block_on_overdue' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-OVERDUE-BASE',
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 5,
            'gross_cost' => 2,
            'gross_margin' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivables')->insert([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 5,
            'paid_amount' => 0,
            'outstanding_amount' => 5,
            'status' => 'pending',
            'due_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
                'payment_method' => 'credit',
                'customer_id' => $customerId,
                'due_at' => now()->addDays(10)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_rejects_controlled_product_sale_without_prescription_or_approval(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_allows_controlled_product_sale_with_prescription_code(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                    'prescription_code' => 'RX-001',
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $controlledProductId,
            'prescription_code' => 'RX-001',
        ]);
    }

    public function test_allows_controlled_product_sale_with_admin_approval_and_consumes_it(): void
    {
        $controlledProductId = $this->seedControlledProduct();
        $cashier = $this->seedCashierUser();
        $admin = $this->seedAdminUser();

        $approvalResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/approvals', [
                'product_id' => $controlledProductId,
                'reason' => 'Urgencia validada por quimico farmaceutico',
            ]);

        $approvalResponse->assertOk();
        $approvalCode = $approvalResponse->json('data.code');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'items' => [[
                    'product_id' => $controlledProductId,
                    'quantity' => 1,
                    'unit_price' => 8.50,
                    'approval_code' => $approvalCode,
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $controlledProductId,
            'approval_code' => $approvalCode,
        ]);

        $this->assertDatabaseHas('sale_approvals', [
            'code' => $approvalCode,
            'status' => 'consumed',
        ]);
    }

    public function test_rejects_sale_for_immobilized_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'status' => 'immobilized',
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    public function test_rejects_sale_for_expired_lot(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedCashierUser();
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');

        DB::table('lots')->where('id', $lotId)->update([
            'expires_at' => now()->subDay()->toDateString(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 1,
                'unit_price' => 3.50,
            ])
            ->assertStatus(422);
    }

    private function seedControlledProduct(): int
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        DB::table('products')->insert([
            'tenant_id' => 10,
            'sku' => 'CLON-2',
            'name' => 'Clonazepam 2mg',
            'status' => 'active',
            'is_controlled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('products')->where('tenant_id', 10)->where('sku', 'CLON-2')->value('id');

        DB::table('lots')->insert([
            'tenant_id' => 10,
            'product_id' => $productId,
            'code' => 'L-CLON-001',
            'expires_at' => '2027-09-30',
            'stock_quantity' => 25,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function seedCashierUser(): User
    {
        $user = User::factory()->create();
        $cashierRoleId = DB::table('roles')->where('code', 'CAJERO')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $cashierRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function seedAdminUser(): User
    {
        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
