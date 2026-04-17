<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceOperationsHistoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_operation_history_index_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');
        $scenario = $this->seedHistoryScenario($admin);
        $this->recordHistoryWorkflow($admin->id, $scenario['receivable_id']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations/history?date=2026-03-12&days_ahead=7&history_days=30&limit=10&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.tracked_count', 2)
            ->assertJsonPath('data.summary.active_count', 2)
            ->assertJsonPath('data.summary.with_history_count', 1)
            ->assertJsonPath('data.summary.resolved_count', 1)
            ->assertJsonFragment([
                'entity_key' => 'receivable:'.$scenario['receivable_id'],
                'workflow_status' => 'resolved',
                'is_currently_prioritized' => true,
                'last_event_type' => 'finance.operation.resolved',
                'timeline_count' => 2,
            ]);
    }

    public function test_reads_finance_operation_history_detail_with_timeline_and_state(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $scenario = $this->seedHistoryScenario($admin);
        $this->recordHistoryWorkflow($admin->id, $scenario['receivable_id']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/reports/finance-operations/receivable/{$scenario['receivable_id']}/history?date=2026-03-12&days_ahead=7&history_days=30&activity_limit=10&stale_follow_up_days=3")
            ->assertOk()
            ->assertJsonPath('data.kind', 'receivable')
            ->assertJsonPath('data.entity_id', $scenario['receivable_id'])
            ->assertJsonPath('data.workflow_status', 'resolved')
            ->assertJsonPath('data.is_currently_prioritized', true)
            ->assertJsonPath('data.is_outstanding', true)
            ->assertJsonPath('data.entity.reference', 'SALE-FIN-HISTORY')
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Seguimiento cerrado para cierre de cobranza.')
            ->assertJsonPath('data.latest_note', 'Seguimiento cerrado para cierre de cobranza.')
            ->assertJsonPath('data.timeline_summary.total_count', 2)
            ->assertJsonPath('data.timeline_summary.acknowledged_count', 1)
            ->assertJsonPath('data.timeline_summary.resolved_count', 1)
            ->assertJsonPath('data.timeline.0.event_type', 'finance.operation.resolved')
            ->assertJsonPath('data.timeline.1.event_type', 'finance.operation.acknowledged');
    }

    public function test_cashier_cannot_read_finance_operation_history(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations/history')
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_read_finance_operation_history_detail(): void
    {
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');
        $scenario = $this->seedHistoryScenario($admin10);
        $this->recordHistoryWorkflow($admin10->id, $scenario['receivable_id']);

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/reports/finance-operations/receivable/{$scenario['receivable_id']}/history")
            ->assertStatus(404);
    }

    private function recordHistoryWorkflow(int $userId, int $receivableId): void
    {
        $stateId = DB::table('finance_operation_states')->insertGetId([
            'tenant_id' => 10,
            'entity_type' => 'receivable',
            'entity_id' => $receivableId,
            'status' => 'resolved',
            'acknowledged_by_user_id' => $userId,
            'acknowledged_at' => '2026-03-12 08:30:00',
            'acknowledgement_note' => 'Equipo de cobranzas revisando el caso.',
            'resolved_by_user_id' => $userId,
            'resolved_at' => '2026-03-12 10:00:00',
            'resolution_note' => 'Seguimiento cerrado para cierre de cobranza.',
            'last_seen_at' => '2026-03-12 10:00:00',
            'created_at' => '2026-03-12 08:30:00',
            'updated_at' => '2026-03-12 10:00:00',
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $userId,
                'domain' => 'finance',
                'event_type' => 'finance.operation.acknowledged',
                'aggregate_type' => 'finance_operation_state',
                'aggregate_id' => $stateId,
                'summary' => 'Finance operation receivable acknowledged.',
                'metadata' => json_encode([
                    'entity_type' => 'receivable',
                    'entity_id' => $receivableId,
                    'status' => 'acknowledged',
                    'note' => 'Equipo de cobranzas revisando el caso.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 08:30:00',
                'created_at' => '2026-03-12 08:30:00',
                'updated_at' => '2026-03-12 08:30:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $userId,
                'domain' => 'finance',
                'event_type' => 'finance.operation.resolved',
                'aggregate_type' => 'finance_operation_state',
                'aggregate_id' => $stateId,
                'summary' => 'Finance operation receivable resolved.',
                'metadata' => json_encode([
                    'entity_type' => 'receivable',
                    'entity_id' => $receivableId,
                    'status' => 'resolved',
                    'note' => 'Seguimiento cerrado para cierre de cobranza.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:00:00',
                'created_at' => '2026-03-12 10:00:00',
                'updated_at' => '2026-03-12 10:00:00',
            ],
        ]);
    }

    private function seedHistoryScenario(User $admin): array
    {
        $customerId = $this->seedCustomer(10, 'Cliente Historial');
        $supplierId = $this->seedSupplier(10, '20181818181', 'Proveedor Historial');

        return [
            'receivable_id' => $this->seedReceivable(10, $admin->id, $customerId, 22.00, '2026-03-10 00:00:00', 'SALE-FIN-HISTORY'),
            'payable_id' => $this->seedPayable(10, $admin->id, $supplierId, 18.00, '2026-03-09 00:00:00', 'PUR-FIN-HISTORY'),
        ];
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

    private function seedPayable(
        int $tenantId,
        int $userId,
        int $supplierId,
        float $amount,
        string $dueAt,
        string $reference,
    ): int {
        $receiptId = DB::table('purchase_receipts')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_order_id' => null,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'received',
            'total_amount' => $amount,
            'received_at' => $dueAt,
            'created_at' => $dueAt,
            'updated_at' => $dueAt,
        ]);

        return DB::table('purchase_payables')->insertGetId([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'purchase_receipt_id' => $receiptId,
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
