<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DueReminderReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_due_reminders_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $customerId = $this->seedCustomer(10, 'Cliente Seguimiento');
        $foreignCustomerId = $this->seedCustomer(20, 'Cliente Externo');
        $supplierId = $this->seedSupplier(10, '20141414141', 'Proveedor Seguimiento');
        $foreignSupplierId = $this->seedSupplier(20, '20242424242', 'Proveedor Externo');

        $this->seedReceivable(10, $admin->id, $customerId, 18.00, now()->subDays(4), 'SALE-RCV-OD');
        $this->seedReceivable(10, $admin->id, $customerId, 9.00, now(), 'SALE-RCV-TODAY');
        $this->seedReceivable(10, $admin->id, $customerId, 12.00, now()->addDays(3), 'SALE-RCV-UP');
        $this->seedReceivable(20, $admin->id, $foreignCustomerId, 99.00, now()->subDays(2), 'SALE-RCV-FOREIGN');

        $this->seedPayable(10, $supplierId, 25.00, now()->subDays(2), 'PUR-PAY-OD');
        $this->seedPayable(10, $supplierId, 14.00, now(), 'PUR-PAY-TODAY');
        $this->seedPayable(10, $supplierId, 30.00, now()->addDays(5), 'PUR-PAY-UP');
        $this->seedPayable(20, $foreignSupplierId, 88.00, now()->addDays(2), 'PUR-PAY-FOREIGN');

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => DB::table('sale_receivables')->where('tenant_id', 10)->where('outstanding_amount', 18)->value('id'),
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente promete pagar mañana',
            'promised_amount' => 18.00,
            'promised_at' => now()->addDay()->startOfDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_payable_follow_ups')->insert([
            'tenant_id' => 10,
            'purchase_payable_id' => DB::table('purchase_payables')->where('tenant_id', 10)->where('outstanding_amount', 25)->value('id'),
            'user_id' => $admin->id,
            'type' => 'note',
            'note' => 'Proveedor solicita confirmacion interna',
            'promised_amount' => null,
            'promised_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/due-reminders?days_ahead=7&limit=2')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.days_ahead', 7)
            ->assertJsonPath('data.receivables.summary.overdue_count', 1)
            ->assertJsonPath('data.receivables.summary.overdue_amount', 18)
            ->assertJsonPath('data.receivables.summary.due_today_count', 1)
            ->assertJsonPath('data.receivables.summary.due_today_amount', 9)
            ->assertJsonPath('data.receivables.summary.upcoming_count', 1)
            ->assertJsonPath('data.receivables.summary.upcoming_amount', 12)
            ->assertJsonPath('data.receivables.overdue.0.sale_reference', 'SALE-RCV-OD')
            ->assertJsonPath('data.receivables.overdue.0.latest_follow_up.type', 'promise')
            ->assertJsonPath('data.receivables.overdue.0.latest_follow_up.note', 'Cliente promete pagar mañana')
            ->assertJsonPath('data.receivables.due_today.0.sale_reference', 'SALE-RCV-TODAY')
            ->assertJsonPath('data.receivables.upcoming.0.sale_reference', 'SALE-RCV-UP')
            ->assertJsonPath('data.payables.summary.overdue_count', 1)
            ->assertJsonPath('data.payables.summary.overdue_amount', 25)
            ->assertJsonPath('data.payables.summary.due_today_count', 1)
            ->assertJsonPath('data.payables.summary.due_today_amount', 14)
            ->assertJsonPath('data.payables.summary.upcoming_count', 1)
            ->assertJsonPath('data.payables.summary.upcoming_amount', 30)
            ->assertJsonPath('data.payables.overdue.0.receipt_reference', 'PUR-PAY-OD')
            ->assertJsonPath('data.payables.overdue.0.latest_follow_up.type', 'note')
            ->assertJsonPath('data.payables.overdue.0.latest_follow_up.note', 'Proveedor solicita confirmacion interna')
            ->assertJsonPath('data.payables.due_today.0.receipt_reference', 'PUR-PAY-TODAY')
            ->assertJsonPath('data.payables.upcoming.0.receipt_reference', 'PUR-PAY-UP')
            ->assertJsonMissing(['sale_reference' => 'SALE-RCV-FOREIGN'])
            ->assertJsonMissing(['receipt_reference' => 'PUR-PAY-FOREIGN']);
    }

    public function test_cashier_cannot_read_due_reminders(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/due-reminders')
            ->assertStatus(403);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

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

    private function seedReceivable(
        int $tenantId,
        int $userId,
        int $customerId,
        float $amount,
        $dueAt,
        string $reference
    ): void {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => $amount,
            'gross_cost' => round($amount * 0.4, 2),
            'gross_margin' => round($amount * 0.6, 2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_receivables')->insert([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'outstanding_amount' => $amount,
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPayable(int $tenantId, int $supplierId, float $amount, $dueAt, string $reference): void
    {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => User::factory()->create()->id,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $amount,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_payables')->insert([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'outstanding_amount' => $amount,
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
