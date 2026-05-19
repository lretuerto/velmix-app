<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceEscalationMetricsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_escalation_metrics_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedEscalationScenario($admin);
        $this->seedEscalationWorkflowMetrics($admin);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalation-metrics?date=2026-03-12&days_ahead=7&history_days=30&stale_follow_up_days=3');

        $response->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.current_backlog.active_count', 4)
            ->assertJsonPath('data.current_backlog.open_count', 2)
            ->assertJsonPath('data.current_backlog.acknowledged_count', 1)
            ->assertJsonPath('data.current_backlog.resolved_but_active_count', 1)
            ->assertJsonPath('data.current_backlog.critical_count', 3)
            ->assertJsonPath('data.current_backlog.warning_count', 1)
            ->assertJsonPath('data.current_backlog.info_count', 0)
            ->assertJsonPath('data.current_backlog.stale_acknowledged_count', 1)
            ->assertJsonPath('data.workflow_events.acknowledged_event_count', 2)
            ->assertJsonPath('data.workflow_events.resolved_event_count', 1)
            ->assertJsonPath('data.resolution_sla.resolved_count', 1)
            ->assertJsonPath('data.resolution_sla.avg_minutes_from_ack_to_resolve', 120)
            ->assertJsonPath('data.resolution_sla.max_minutes_from_ack_to_resolve', 120)
            ->assertJsonPath('data.resolution_sla.within_240_minutes_rate', 100)
            ->assertJsonPath('data.recent_resolutions.0.code', 'finance.broken_promise')
            ->assertJsonPath('data.recent_resolutions.0.minutes_from_ack_to_resolve', 120)
            ->assertJsonFragment([
                'code' => 'finance.stale_acknowledged',
                'workflow_status' => 'acknowledged',
            ]);

        $this->assertNotNull($response->json('data.current_backlog.oldest_acknowledged_age_minutes'));
    }

    public function test_cashier_cannot_read_finance_escalation_metrics(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalation-metrics')
            ->assertStatus(403);
    }

    private function seedEscalationWorkflowMetrics(User $admin): void
    {
        $resolvedStateId = DB::table('finance_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'finance.broken_promise',
            'status' => 'resolved',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-12 09:00:00',
            'acknowledgement_note' => 'Cobranza priorizada.',
            'resolved_by_user_id' => $admin->id,
            'resolved_at' => '2026-03-12 11:00:00',
            'resolution_note' => 'Promesa renegociada y documentada.',
            'last_seen_at' => '2026-03-12 11:00:00',
            'created_at' => '2026-03-12 09:00:00',
            'updated_at' => '2026-03-12 11:00:00',
        ]);

        $acknowledgedStateId = DB::table('finance_escalation_states')->insertGetId([
            'tenant_id' => 10,
            'escalation_code' => 'finance.stale_acknowledged',
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => '2026-03-11 08:00:00',
            'acknowledgement_note' => 'Tesoreria revisando backlog.',
            'resolved_by_user_id' => null,
            'resolved_at' => null,
            'resolution_note' => null,
            'last_seen_at' => '2026-03-11 08:00:00',
            'created_at' => '2026-03-11 08:00:00',
            'updated_at' => '2026-03-11 08:00:00',
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.acknowledged',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Finance escalation finance.broken_promise acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.broken_promise',
                    'status' => 'acknowledged',
                    'note' => 'Cobranza priorizada.',
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
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Finance escalation finance.broken_promise resolved.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.broken_promise',
                    'status' => 'resolved',
                    'note' => 'Promesa renegociada y documentada.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 11:00:00',
                'created_at' => '2026-03-12 11:00:00',
                'updated_at' => '2026-03-12 11:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $admin->id,
                'domain' => 'finance',
                'event_type' => 'finance.escalation.acknowledged',
                'aggregate_type' => 'finance_escalation_state',
                'aggregate_id' => $acknowledgedStateId,
                'summary' => 'Finance escalation finance.stale_acknowledged acknowledged.',
                'metadata' => json_encode([
                    'escalation_code' => 'finance.stale_acknowledged',
                    'status' => 'acknowledged',
                    'note' => 'Tesoreria revisando backlog.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-11 08:00:00',
                'created_at' => '2026-03-11 08:00:00',
                'updated_at' => '2026-03-11 08:00:00',
            ],
        ]);
    }

    private function seedEscalationScenario(User $admin): void
    {
        $customerId = $this->seedCustomer(10, 'Cliente Escalacion');
        $supplierId = $this->seedSupplier(10, '20171717171', 'Proveedor Escalacion');

        $brokenReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-ESC-MET-BROKEN');
        $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-14 00:00:00', 'SALE-FIN-ESC-MET-UPCOMING');
        $acknowledgedPayableId = $this->seedPayable(10, $admin->id, $supplierId, 34.00, '2026-02-04 00:00:00', 'PUR-FIN-ESC-MET-ACK');

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
            'acknowledgement_note' => 'Tesoreria ya tomo el caso.',
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
