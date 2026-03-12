<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSaleReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_sales_for_current_tenant_only(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-LIST-10');
        $this->createCompletedSaleForTenant(20, 'SALE-LIST-20');
        $user = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/pos/sales')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $saleId,
                'reference' => 'SALE-LIST-10',
                'payment_method' => 'cash',
            ])
            ->assertJsonMissing([
                'reference' => 'SALE-LIST-20',
            ]);
    }

    public function test_reads_sale_detail_with_items_and_voucher_summary(): void
    {
        [$saleId, $lotId] = $this->createCompletedSaleForTenant(10, 'SALE-DETAIL-10');
        $user = $this->seedUserWithRole(10, 'CAJERO');

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-123456',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.id', $saleId)
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.voucher.status', 'accepted')
            ->assertJsonPath('data.movement_count', 1)
            ->assertJsonPath('data.gross_cost', 6.5)
            ->assertJsonPath('data.gross_margin', 11)
            ->assertJsonPath('data.items.0.unit_cost_snapshot', 1.3)
            ->assertJsonPath('data.items.0.lot_code', DB::table('lots')->where('id', $lotId)->value('code'));
    }

    public function test_reads_credit_sale_detail_with_customer_and_receivable(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => 'dni',
            'document_number' => '11223344',
            'name' => 'Cliente Read AR',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-CREDIT-READ',
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 20.00,
            'gross_cost' => 8.00,
            'gross_margin' => 12.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivables')->insert([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 20.00,
            'paid_amount' => 0,
            'outstanding_amount' => 20.00,
            'status' => 'pending',
            'due_at' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'credit')
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.customer.name', 'Cliente Read AR')
            ->assertJsonPath('data.receivable.status', 'pending')
            ->assertJsonPath('data.receivable.outstanding_amount', 20);
    }

    public function test_reads_cancelled_sale_detail(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-CANCELLED-10');
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/pos/sales/{$saleId}/cancel", [
                'reason' => 'Anulacion operativa',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Anulacion operativa');
    }

    public function test_reads_credited_sale_detail_with_credit_note_summary(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-CREDITED-10');
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 10,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-CR-001',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');

        DB::table('sales')->where('id', $saleId)->update([
            'status' => 'credited',
            'credited_by_user_id' => $admin->id,
            'credit_reason' => 'Devolucion total',
            'credited_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_credit_notes')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'electronic_voucher_id' => $voucherId,
            'series' => 'NC01',
            'number' => 1,
            'status' => 'accepted',
            'reason' => 'Devolucion total',
            'total_amount' => 17.50,
            'refunded_amount' => 17.50,
            'refund_payment_method' => 'cash',
            'sunat_ticket' => 'SUNAT-NC-001',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pos/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'credited')
            ->assertJsonPath('data.credit_reason', 'Devolucion total')
            ->assertJsonPath('data.credit_note.status', 'accepted')
            ->assertJsonPath('data.credit_note.series', 'NC01');
    }

    public function test_lists_sale_once_even_with_multiple_credit_notes(): void
    {
        [$saleId] = $this->createCompletedSaleForTenant(10, 'SALE-MULTI-CN-10');
        $admin = $this->seedUserWithRole(10, 'ADMIN');

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 11,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-CR-011',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');

        DB::table('sale_credit_notes')->insert([
            [
                'tenant_id' => 10,
                'sale_id' => $saleId,
                'electronic_voucher_id' => $voucherId,
                'series' => 'NC01',
                'number' => 1,
                'status' => 'accepted',
                'reason' => 'Primera parcial',
                'total_amount' => 7,
                'refunded_amount' => 7,
                'refund_payment_method' => 'cash',
                'sunat_ticket' => 'SUNAT-NC-011',
                'rejection_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'sale_id' => $saleId,
                'electronic_voucher_id' => $voucherId,
                'series' => 'NC01',
                'number' => 2,
                'status' => 'pending',
                'reason' => 'Segunda parcial',
                'total_amount' => 10.5,
                'refunded_amount' => 10.5,
                'refund_payment_method' => 'cash',
                'sunat_ticket' => null,
                'rejection_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/pos/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $saleId)
            ->assertJsonPath('data.0.credit_summary.count', 2)
            ->assertJsonPath('data.0.credit_summary.credited_total', 17.5)
            ->assertJsonPath('data.0.credit_note.status', 'pending');
    }

    private function createCompletedSaleForTenant(int $tenantId, string $reference): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $user = $this->seedUserWithRole($tenantId, 'CAJERO');
        $lotId = DB::table('lots')->where('tenant_id', $tenantId)->value('id');
        $lotCode = DB::table('lots')->where('id', $lotId)->value('code');
        $productId = DB::table('lots')->where('id', $lotId)->value('product_id');

        DB::table('sales')->insert([
            'id' => DB::table('sales')->max('id') + 1,
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'cancelled_by_user_id' => null,
            'reference' => $reference,
            'status' => 'completed',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'total_amount' => 17.50,
            'gross_cost' => 6.50,
            'gross_margin' => 11.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->where('reference', $reference)->value('id');

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'quantity' => 5,
            'unit_price' => 3.50,
            'unit_cost_snapshot' => 1.30,
            'line_total' => 17.50,
            'cost_amount' => 6.50,
            'gross_margin' => 11.00,
            'prescription_code' => null,
            'approval_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantId,
            'lot_id' => $lotId,
            'product_id' => $productId,
            'sale_id' => $saleId,
            'type' => 'sale',
            'quantity' => -5,
            'reference' => $reference,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $lotId, $lotCode];
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
}
