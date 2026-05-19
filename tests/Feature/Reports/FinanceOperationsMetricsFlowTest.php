<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceOperationsMetricsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_operation_metrics_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $scenario = $this->seedMetricsScenario($admin);
        $this->seedWorkflowMetrics($admin->id, $scenario);

        $response = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations/metrics?date=2026-03-12&days_ahead=30&history_days=30');

        $response->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.current_backlog.active_count', 3)
            ->assertJsonPath('data.current_backlog.open_count', 1)
            ->assertJsonPath('data.current_backlog.acknowledged_count', 1)
            ->assertJsonPath('data.current_backlog.resolved_but_active_count', 1)
            ->assertJsonPath('data.current_backlog.high_count', 1)
            ->assertJsonPath('data.current_backlog.attention_count', 2)
            ->assertJsonPath('data.current_backlog.stale_acknowledged_count', 1)
            ->assertJsonPath('data.backlog_by_kind.receivable.count', 2)
            ->assertJsonPath('data.backlog_by_kind.payable.count', 1)
            ->assertJsonPath('data.queue_aging.overdue_count', 3)
            ->assertJsonPath('data.queue_aging.oldest_overdue_days', 36)
            ->assertJsonPath('data.workflow_events.acknowledged_event_count', 2)
            ->assertJsonPath('data.workflow_events.resolved_event_count', 1)
            ->assertJsonPath('data.resolution_sla.resolved_count', 1)
            ->assertJsonPath('data.resolution_sla.avg_minutes_from_ack_to_resolve', 90)
            ->assertJsonPath('data.resolution_sla.max_minutes_from_ack_to_resolve', 90)
            ->assertJsonPath('data.resolution_sla.within_240_minutes_rate', 100)
            ->assertJsonPath('data.recent_resolutions.0.entity_key', 'receivable:'.$scenario['resolved_receivable_id'])
            ->assertJsonPath('data.recent_resolutions.0.minutes_from_ack_to_resolve', 90)
            ->assertJsonFragment([
                'entity_key' => 'payable:'.$scenario['acknowledged_payable_id'],
                'workflow_status' => 'acknowledged',
                'escalation_level' => 'high',
            ]);

        $this->assertNotNull($response->json('data.current_backlog.oldest_acknowledged_age_minutes'));
    }

    public function test_cashier_cannot_read_finance_operation_metrics(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-operations/metrics')
            ->assertStatus(403);
    }

    private function seedWorkflowMetrics(int $userId, array $scenario): void
    {
        $resolvedStateId = DB::table('finance_operation_states')->insertGetId([
            'tenant_id' => 10,
            'entity_type' => 'receivable',
            'entity_id' => $scenario['resolved_receivable_id'],
            'status' => 'resolved',
            'acknowledged_by_user_id' => $userId,
            'acknowledged_at' => '2026-03-12 08:30:00',
            'acknowledgement_note' => 'Caso de cobranza en revision.',
            'resolved_by_user_id' => $userId,
            'resolved_at' => '2026-03-12 10:00:00',
            'resolution_note' => 'Seguimiento completado para la cuenta por cobrar.',
            'last_seen_at' => '2026-03-12 10:00:00',
            'created_at' => '2026-03-12 08:30:00',
            'updated_at' => '2026-03-12 10:00:00',
        ]);

        $acknowledgedStateId = DB::table('finance_operation_states')->insertGetId([
            'tenant_id' => 10,
            'entity_type' => 'payable',
            'entity_id' => $scenario['acknowledged_payable_id'],
            'status' => 'acknowledged',
            'acknowledged_by_user_id' => $userId,
            'acknowledged_at' => '2026-03-11 08:00:00',
            'acknowledgement_note' => 'Pago proveedor en seguimiento.',
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
                'user_id' => $userId,
                'domain' => 'finance',
                'event_type' => 'finance.operation.acknowledged',
                'aggregate_type' => 'finance_operation_state',
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Finance operation receivable acknowledged.',
                'metadata' => json_encode([
                    'entity_type' => 'receivable',
                    'entity_id' => $scenario['resolved_receivable_id'],
                    'status' => 'acknowledged',
                    'note' => 'Caso de cobranza en revision.',
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
                'aggregate_id' => $resolvedStateId,
                'summary' => 'Finance operation receivable resolved.',
                'metadata' => json_encode([
                    'entity_type' => 'receivable',
                    'entity_id' => $scenario['resolved_receivable_id'],
                    'status' => 'resolved',
                    'note' => 'Seguimiento completado para la cuenta por cobrar.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-12 10:00:00',
                'created_at' => '2026-03-12 10:00:00',
                'updated_at' => '2026-03-12 10:00:00',
            ],
            [
                'tenant_id' => 10,
                'user_id' => $userId,
                'domain' => 'finance',
                'event_type' => 'finance.operation.acknowledged',
                'aggregate_type' => 'finance_operation_state',
                'aggregate_id' => $acknowledgedStateId,
                'summary' => 'Finance operation payable acknowledged.',
                'metadata' => json_encode([
                    'entity_type' => 'payable',
                    'entity_id' => $scenario['acknowledged_payable_id'],
                    'status' => 'acknowledged',
                    'note' => 'Pago proveedor en seguimiento.',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-03-11 08:00:00',
                'created_at' => '2026-03-11 08:00:00',
                'updated_at' => '2026-03-11 08:00:00',
            ],
        ]);
    }

    private function seedMetricsScenario(User $admin): array
    {
        $customerId = $this->seedCustomer(10, 'Cliente Metricas');
        $supplierId = $this->seedSupplier(10, '20191919191', 'Proveedor Metricas');

        return [
            'resolved_receivable_id' => $this->seedReceivable(10, $admin->id, $customerId, 26.00, '2026-03-10 00:00:00', 'SALE-FIN-MET-RES'),
            'acknowledged_payable_id' => $this->seedPayable(10, $admin->id, $supplierId, 34.00, '2026-02-04 00:00:00', 'PUR-FIN-MET-ACK'),
            'open_receivable_id' => $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-08 00:00:00', 'SALE-FIN-MET-OPEN'),
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
