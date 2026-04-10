<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleReceivableFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_sale_creates_receivable_for_customer(): void
    {
        $cashier = $this->seedBaseCatalogAndCashier();
        $lotId = DB::table('lots')->where('tenant_id', 10)->value('id');
        $customerId = $this->seedCustomer(10, 'Cliente Credito');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 3,
                'unit_price' => 4.00,
                'payment_method' => 'credit',
                'customer_id' => $customerId,
                'due_at' => now()->addDays(15)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'credit')
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.receivable.status', 'pending');

        $this->assertDatabaseHas('sale_receivables', [
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'status' => 'pending',
            'paid_amount' => 0.00,
            'outstanding_amount' => 12.00,
        ]);
    }

    public function test_lists_reads_and_pays_sale_receivable(): void
    {
        $cashier = $this->seedBaseCatalogAndCashier();
        [$receivableId, $customerId] = $this->seedReceivableScenario(10, $cashier->id);
        $this->seedReceivableScenario(20, $this->seedTenantUser(20, 'CAJERO')->id);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 50,
            ])
            ->assertOk();

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/sales/receivables')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $receivableId,
                'status' => 'pending',
            ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/sales/receivables/{$receivableId}")
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customerId)
            ->assertJsonPath('data.outstanding_amount', 18);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/sales/receivables/{$receivableId}/payments", [
                'amount' => 8,
                'payment_method' => 'cash',
                'reference' => 'COBRO-001',
            ])
            ->assertOk()
            ->assertJsonPath('data.cash_movement_id', 1)
            ->assertJsonPath('data.status', 'partial_paid')
            ->assertJsonPath('data.outstanding_amount', 10);

        $this->assertDatabaseHas('cash_movements', [
            'tenant_id' => 10,
            'type' => 'receivable_in',
            'amount' => 8.00,
            'reference' => 'COBRO-001',
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/cash/sessions/current')
            ->assertOk()
            ->assertJsonPath('data.receivable_cash_total', 8)
            ->assertJsonPath('data.expected_amount', 58);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/sales/receivables/{$receivableId}/payments", [
                'amount' => 10,
                'payment_method' => 'transfer',
                'reference' => 'COBRO-002',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.outstanding_amount', 0);

        $this->assertDatabaseHas('sale_receivables', [
            'id' => $receivableId,
            'status' => 'paid',
            'paid_amount' => 18.00,
            'outstanding_amount' => 0.00,
        ]);
    }

    public function test_rejects_cash_receivable_payment_without_open_cash_session(): void
    {
        $cashier = $this->seedBaseCatalogAndCashier();
        [$receivableId] = $this->seedReceivableScenario(10, $cashier->id);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/sales/receivables/{$receivableId}/payments", [
                'amount' => 5,
                'payment_method' => 'cash',
                'reference' => 'COBRO-NO-CAJA',
            ])
            ->assertStatus(422);
    }

    public function test_can_register_receivable_follow_up_and_read_it_from_detail_and_customer_statement(): void
    {
        $cashier = $this->seedBaseCatalogAndCashier();
        [$receivableId, $customerId] = $this->seedReceivableScenario(10, $cashier->id);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/sales/receivables/{$receivableId}/follow-ups", [
                'type' => 'promise',
                'note' => 'Cliente promete cancelar el viernes',
                'promised_amount' => 9,
                'promised_at' => now()->addDays(2)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.type', 'promise')
            ->assertJsonPath('data.promised_amount', 9)
            ->assertJsonPath('data.outstanding_snapshot', 18)
            ->assertJsonPath('data.user.id', $cashier->id);

        $this->assertDatabaseHas('sale_receivable_follow_ups', [
            'tenant_id' => 10,
            'sale_receivable_id' => $receivableId,
            'type' => 'promise',
            'note' => 'Cliente promete cancelar el viernes',
            'promised_amount' => 9.00,
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/sales/receivables/{$receivableId}/follow-ups")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'promise')
            ->assertJsonPath('data.0.note', 'Cliente promete cancelar el viernes');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/sales/receivables/{$receivableId}")
            ->assertOk()
            ->assertJsonPath('data.latest_follow_up.type', 'promise')
            ->assertJsonPath('data.latest_follow_up.note', 'Cliente promete cancelar el viernes');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/sales/customers/{$customerId}/statement")
            ->assertOk()
            ->assertJsonPath('data.summary.follow_up_count', 1)
            ->assertJsonPath('data.summary.promised_follow_up_count', 1)
            ->assertJsonPath('data.follow_ups.0.sale_reference', 'SALE-REC-10')
            ->assertJsonPath('data.follow_ups.0.type', 'promise');
    }

    public function test_reads_receivable_aging_and_customer_statement(): void
    {
        $cashier = $this->seedBaseCatalogAndCashier();
        $customerId = $this->seedCustomer(10, 'Cliente Estado');
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-AR-STMT',
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 20.00,
            'gross_cost' => 8.00,
            'gross_margin' => 12.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentReceivableId = DB::table('sale_receivables')->insertGetId([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 20.00,
            'paid_amount' => 5.00,
            'outstanding_amount' => 15.00,
            'status' => 'partial_paid',
            'due_at' => now()->subDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivable_payments')->insert([
            'sale_receivable_id' => $currentReceivableId,
            'user_id' => $cashier->id,
            'amount' => 5.00,
            'payment_method' => 'cash',
            'reference' => 'PAY-STMT-01',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paidSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $cashier->id,
            'customer_id' => $customerId,
            'reference' => 'SALE-AR-PAID',
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 10.00,
            'gross_cost' => 4.00,
            'gross_margin' => 6.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivables')->insert([
            'tenant_id' => 10,
            'customer_id' => $customerId,
            'sale_id' => $paidSaleId,
            'total_amount' => 10.00,
            'paid_amount' => 10.00,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
            'due_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/sales/receivables/aging')
            ->assertOk()
            ->assertJsonPath('data.summary.overdue_1_30.count', 1)
            ->assertJsonPath('data.summary.overdue_1_30.amount', 15)
            ->assertJsonPath('data.summary.paid.count', 1)
            ->assertJsonPath('data.summary.paid.amount', 10);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/sales/customers/{$customerId}/statement")
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Cliente Estado')
            ->assertJsonPath('data.summary.sales_total', 30)
            ->assertJsonPath('data.summary.receivables_total', 30)
            ->assertJsonPath('data.summary.payments_total', 5)
            ->assertJsonPath('data.summary.outstanding_total', 15)
            ->assertJsonPath('data.payments.0.reference', 'PAY-STMT-01');
    }

    private function seedBaseCatalogAndCashier(): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        return $this->seedTenantUser(10, 'CAJERO');
    }

    private function seedTenantUser(int $tenantId, string $roleCode): User
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

    private function seedCustomer(int $tenantId, string $name): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => 'dni',
            'document_number' => (string) random_int(10000000, 99999999),
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReceivableScenario(int $tenantId, int $userId): array
    {
        $customerId = $this->seedCustomer($tenantId, 'Cliente '.$tenantId);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'reference' => 'SALE-REC-'.$tenantId,
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => 18.00,
            'gross_cost' => 7.00,
            'gross_margin' => 11.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receivableId = DB::table('sale_receivables')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => 18.00,
            'paid_amount' => 0.00,
            'outstanding_amount' => 18.00,
            'status' => 'pending',
            'due_at' => now()->addDays(20),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$receivableId, $customerId];
    }
}
