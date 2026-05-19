<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceEscalationReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_finance_escalations_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $scenario = $this->seedEscalationScenario($admin);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalations?date=2026-03-12&days_ahead=7&limit=10&stale_follow_up_days=3')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.summary.open_count', 3)
            ->assertJsonPath('data.summary.critical_count', 2)
            ->assertJsonPath('data.summary.warning_count', 1)
            ->assertJsonPath('data.summary.info_count', 0)
            ->assertJsonPath('data.summary.workflow.open_count', 2)
            ->assertJsonPath('data.summary.workflow.acknowledged_count', 1)
            ->assertJsonPath('data.summary.workflow.resolved_count', 0)
            ->assertJsonPath('data.alert_summary.open_count', 4)
            ->assertJsonPath('data.alert_summary.critical_count', 3)
            ->assertJsonPath('data.alert_summary.warning_count', 1)
            ->assertJsonPath('data.alert_summary.workflow.open_count', 4)
            ->assertJsonPath('data.summary.by_kind.receivable_count', 2)
            ->assertJsonPath('data.summary.by_kind.payable_count', 1)
            ->assertJsonPath('data.summary.flags.broken_promise_count', 1)
            ->assertJsonPath('data.summary.flags.stale_acknowledged_count', 1)
            ->assertJsonPath('data.summary.flags.missing_follow_up_count', 1)
            ->assertJsonPath('data.alerts.0.code', 'finance.stale_acknowledged')
            ->assertJsonPath('data.alerts.0.workflow_status', 'open')
            ->assertJsonPath('data.alerts.1.code', 'finance.broken_promise')
            ->assertJsonPath('data.items.0.entity_key', 'payable:'.$scenario['acknowledged_payable_id'])
            ->assertJsonPath('data.items.0.severity', 'critical')
            ->assertJsonPath('data.items.0.workflow_status', 'acknowledged')
            ->assertJsonPath('data.items.1.entity_key', 'receivable:'.$scenario['broken_receivable_id'])
            ->assertJsonPath('data.items.1.title', 'Cobranza con promesa rota')
            ->assertJsonFragment([
                'entity_key' => 'receivable:'.$scenario['upcoming_receivable_id'],
                'severity' => 'warning',
                'workflow_status' => 'open',
            ]);

        $this->assertNotEmpty($this->getJsonPayload('/reports/finance-escalations?date=2026-03-12&days_ahead=7&limit=10&stale_follow_up_days=3', $admin)['recommended_actions']);
    }

    public function test_cashier_cannot_read_finance_escalations(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/finance-escalations')
            ->assertStatus(403);
    }

    private function getJsonPayload(string $uri, User $user): array
    {
        return $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson($uri)
            ->assertOk()
            ->json('data');
    }

    private function seedEscalationScenario(User $admin): array
    {
        $customerId = $this->seedCustomer(10, 'Cliente Escalacion');
        $supplierId = $this->seedSupplier(10, '20171717171', 'Proveedor Escalacion');

        $brokenReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 20.00, '2026-03-10 00:00:00', 'SALE-FIN-ESC-BROKEN');
        $upcomingReceivableId = $this->seedReceivable(10, $admin->id, $customerId, 15.00, '2026-03-14 00:00:00', 'SALE-FIN-ESC-UPCOMING');
        $acknowledgedPayableId = $this->seedPayable(10, $admin->id, $supplierId, 34.00, '2026-02-04 00:00:00', 'PUR-FIN-ESC-ACK');

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

        return [
            'broken_receivable_id' => $brokenReceivableId,
            'upcoming_receivable_id' => $upcomingReceivableId,
            'acknowledged_payable_id' => $acknowledgedPayableId,
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
