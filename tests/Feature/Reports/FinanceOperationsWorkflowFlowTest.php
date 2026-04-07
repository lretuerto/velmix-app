<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceOperationsWorkflowFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_acknowledge_and_resolve_finance_operation(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');
        $customerId = $this->seedCustomer(10, 'Cliente Workflow');
        $receivableId = $this->seedReceivable(10, $admin->id, $customerId, 25.00, '2026-03-10 00:00:00', 'SALE-FIN-WORKFLOW');

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => $receivableId,
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente prometio regularizar',
            'promised_amount' => 25.00,
            'outstanding_snapshot' => 25.00,
            'promised_at' => '2026-03-11 00:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 08:00:00',
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/reports/finance-operations/receivable/{$receivableId}?date=2026-03-12&days_ahead=7&stale_follow_up_days=3")
            ->assertOk()
            ->assertJsonPath('data.kind', 'receivable')
            ->assertJsonPath('data.entity_id', $receivableId)
            ->assertJsonPath('data.workflow_status', 'open')
            ->assertJsonPath('data.is_currently_prioritized', true)
            ->assertJsonPath('data.item.reference', 'SALE-FIN-WORKFLOW')
            ->assertJsonPath('data.priority_item.escalation_level', 'critical')
            ->assertJsonPath('data.state', null);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/reports/finance-operations/receivable/{$receivableId}/acknowledge", [
                'note' => 'Cobranzas ya tomo el caso.',
                'date' => '2026-03-12',
                'days_ahead' => 7,
                'stale_follow_up_days' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.state.entity_type', 'receivable')
            ->assertJsonPath('data.state.entity_id', $receivableId)
            ->assertJsonPath('data.state.status', 'acknowledged')
            ->assertJsonPath('data.item.workflow_status', 'acknowledged');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations?date=2026-03-12&days_ahead=7&limit=10&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.workflow.acknowledged_count', 1)
            ->assertJsonFragment([
                'entity_id' => $receivableId,
                'workflow_status' => 'acknowledged',
            ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/reports/finance-operations/receivable/{$receivableId}/resolve", [
                'note' => 'Seguimiento completado, a la espera de pago.',
                'date' => '2026-03-12',
                'days_ahead' => 7,
                'stale_follow_up_days' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Seguimiento completado, a la espera de pago.')
            ->assertJsonPath('data.item.workflow_status', 'resolved');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/reports/finance-operations/receivable/{$receivableId}?date=2026-03-12&days_ahead=7&stale_follow_up_days=3")
            ->assertOk()
            ->assertJsonPath('data.workflow_status', 'resolved')
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Seguimiento completado, a la espera de pago.');
    }

    public function test_cashier_cannot_manage_finance_operation_workflow(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $customerId = $this->seedCustomer(10, 'Cliente Cajero');
        $receivableId = $this->seedReceivable(10, $cashier->id, $customerId, 12.00, '2026-03-11 00:00:00', 'SALE-FIN-CASHIER');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/reports/finance-operations/receivable/{$receivableId}/acknowledge", [
                'note' => 'Intento sin permiso.',
            ])
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_read_finance_operation_detail(): void
    {
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');
        $customerId = $this->seedCustomer(10, 'Cliente Otro Tenant');
        $receivableId = $this->seedReceivable(10, $admin10->id, $customerId, 14.00, '2026-03-10 00:00:00', 'SALE-FIN-FOREIGN-DETAIL');

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/reports/finance-operations/receivable/{$receivableId}")
            ->assertStatus(404);
    }

    public function test_resolve_requires_note(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $customerId = $this->seedCustomer(10, 'Cliente Sin Nota');
        $receivableId = $this->seedReceivable(10, $admin->id, $customerId, 12.00, '2026-03-10 00:00:00', 'SALE-FIN-NOTE');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/reports/finance-operations/receivable/{$receivableId}/resolve", [])
            ->assertStatus(422);
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

    private function seedReceivable(
        int $tenantId,
        int $userId,
        int $customerId,
        float $amount,
        string $dueAt,
        string $reference,
    ): int {
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
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);

        return DB::table('sale_receivables')->insertGetId([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'sale_id' => $saleId,
            'total_amount' => $amount,
            'paid_amount' => 0,
            'outstanding_amount' => $amount,
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);
    }
}
