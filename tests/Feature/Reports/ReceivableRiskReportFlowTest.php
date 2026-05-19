<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReceivableRiskReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_receivable_risk_summary_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        $customerA = $this->seedCustomer(10, 'Cliente A', 100);
        $customerB = $this->seedCustomer(10, 'Cliente B', 50);
        $foreignCustomer = $this->seedCustomer(20, 'Cliente Otro', 999);

        $this->seedReceivable(10, $admin->id, $customerA, 30, now()->subDays(4));
        $this->seedReceivable(10, $admin->id, $customerA, 10, now()->addDays(6));
        $this->seedReceivable(10, $admin->id, $customerB, 12, now()->addDays(3));
        $this->seedReceivable(20, $admin->id, $foreignCustomer, 90, now()->subDays(3));

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/receivable-risk')
            ->assertOk()
            ->assertJsonPath('data.summary.customer_count', 2)
            ->assertJsonPath('data.summary.receivable_count', 3)
            ->assertJsonPath('data.summary.outstanding_total', 52)
            ->assertJsonPath('data.summary.overdue_total', 30)
            ->assertJsonPath('data.top_overdue_customers.0.customer_name', 'Cliente A')
            ->assertJsonPath('data.top_overdue_customers.0.available_credit', 60)
            ->assertJsonPath('data.top_exposed_customers.0.outstanding_total', 40);
    }

    public function test_cashier_cannot_read_receivable_risk_summary(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/receivable-risk')
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

    private function seedCustomer(int $tenantId, string $name, float $creditLimit): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => 'dni',
            'document_number' => (string) random_int(10000000, 99999999),
            'name' => $name,
            'credit_limit' => $creditLimit,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReceivable(int $tenantId, int $userId, int $customerId, float $amount, $dueAt): void
    {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'customer_id' => $customerId,
            'reference' => 'SALE-RISK-'.random_int(1000, 9999),
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
}
