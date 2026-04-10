<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceEscalationHistoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_escalation_history_index_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->recordEscalationWorkflow($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalations/history?date=2026-03-12&days_ahead=7&history_days=30&limit=10&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.tracked_count', 4)
            ->assertJsonPath('data.summary.active_count', 4)
            ->assertJsonPath('data.summary.with_history_count', 1)
            ->assertJsonPath('data.summary.resolved_count', 1)
            ->assertJsonFragment([
                'code' => 'finance.stale_acknowledged',
                'workflow_status' => 'resolved',
                'is_currently_triggered' => true,
                'last_event_type' => 'finance.escalation.resolved',
                'timeline_count' => 2,
            ]);
    }

    public function test_reads_finance_escalation_detail_with_timeline_and_state(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->recordEscalationWorkflow($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalations/finance.stale_acknowledged?date=2026-03-12&days_ahead=7&history_days=30&activity_limit=10&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.code', 'finance.stale_acknowledged')
            ->assertJsonPath('data.workflow_status', 'resolved')
            ->assertJsonPath('data.is_currently_triggered', true)
            ->assertJsonPath('data.active_item.code', 'finance.stale_acknowledged')
            ->assertJsonPath('data.state.status', 'resolved')
            ->assertJsonPath('data.state.resolution_note', 'Alerta agregada cerrada tras revisión del backlog.')
            ->assertJsonPath('data.latest_note', 'Alerta agregada cerrada tras revisión del backlog.')
            ->assertJsonPath('data.timeline_summary.total_count', 2)
            ->assertJsonPath('data.timeline_summary.acknowledged_count', 1)
            ->assertJsonPath('data.timeline_summary.resolved_count', 1)
            ->assertJsonPath('data.timeline.0.event_type', 'finance.escalation.resolved')
            ->assertJsonPath('data.timeline.1.event_type', 'finance.escalation.acknowledged');
    }

    public function test_cashier_cannot_read_finance_escalation_history(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalations/history')
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_read_finance_escalation_history_detail(): void
    {
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');
        $this->seedEscalationScenario($admin10);
        $this->recordEscalationWorkflow($admin10);

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson('/reports/finance-escalations/finance.stale_acknowledged')
            ->assertStatus(404);
    }

    private function recordEscalationWorkflow(User $admin): void
    {
        $stateId = DB::table('finance_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'finance.stale_acknowledged',
            'status' => 'resolved',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-12 09:00:00',
            'acknowledgement_note' => 'Tesoreria ya tomó la alerta agregada.',
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => '2026-03-12 10:00:00',
            'resolution_note' => 'Alerta agregada cerrada tras revisión del backlog.',
            'last_seen_at' => '2026-03-12 10:00:00',
            'created_at' => '2026-03-12 09:00:00',
            'updated_at' => '2026-03-12 10:00:00',
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.acknowledged',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $stateId,
                'summary' => 'Finance escalation finance.stale_acknowledged acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.stale_acknowledged',
                    'status' => 'acknowledged',
                    'note' => 'Tesoreria ya tomó la alerta agregada.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 09:00:00',
                'created_at' => '2026-03-12 09:00:00',
                'updated_at' => '2026-03-12 09:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.resolved',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $stateId,
                'summary' => 'Finance escalation finance.stale_acknowledged resolved.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.stale_acknowledged',
                    'status' => 'resolved',
                    'note' => 'Alerta agregada cerrada tras revisión del backlog.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:00:00',
                'created_at' => '2026-03-12 10:00:00',
                'updated_at' => '2026-03-12 10:00:00',
            ],
        ]);
    }

    private function seedEscalationScenario(User $admin): void
    {
        $customerId = $this->seedCustomer(10, 'Cliente Workflow Escalacion');
        $supplierId = $this->seedSupplier(10, '20162626262', 'Proveedor Workflow Escalacion');

        $brokenReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-ESC-HISTORY-BROKEN');
        $acknowledgedPayableId = $this->seedPayable(10, $admin->id, $supplierId, 34.00, '2026-02-04 00:00:00', 'PUR-FIN-ESC-HISTORY-ACK');
        $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-14 00:00:00', 'SALE-FIN-ESC-HISTORY-UPCOMING');

        DB::table('sale_receivable_follow_ups')->insert([
            'tenant_id' => 10,
            'sale_receivable_id' => $brokenReceivableId,
            'user_id' => $admin->id,
            'type' => 'promise',
            'note' => 'Cliente prometio pagar ayer.',
            'promised_amount' => 20.00,
            'outstanding_snapshot' => 20.00,
            'promised_at' => '2026-03-11 00:00:00',
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:00:00',
        ]);

        DB::table('finance_operation_states')->insert([
            'tenant_id' => 10,
            'entity_type' => 'payable',
            'entity_id' => $acknowledgedPayableId,
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-11 08:00:00',
            'acknowledgement_note' => 'Tesoreria ya tomó el caso.',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
            'last_seen_at' => '2026-03-11 08:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 08:00:00',
        ]);
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
