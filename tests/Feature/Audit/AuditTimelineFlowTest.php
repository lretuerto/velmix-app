<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTimelineFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_operational_audit_timeline_for_current_tenant(): void
    {
        $this->seedBaseCatalog();
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');
        $lotId = DB::table('lots')->where('tenant_id', 10)->where('code', 'L-PARA-001')->value('id');
        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => 10,
            'tax_id' => '20181818181',
            'name' => 'Proveedor Auditoria',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/sessions/open', [
                'opening_amount' => 100,
            ])
            ->assertOk();

        $saleResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/pos/sales', [
                'lot_id' => $lotId,
                'quantity' => 2,
                'unit_price' => 3.50,
            ])
            ->assertOk();

        $saleId = $saleResponse->json('data.sale_id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/cash/movements', [
                'type' => 'manual_in',
                'amount' => 5,
                'reference' => 'AUD-CASH-001',
                'notes' => 'Ingreso de auditoria',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/purchases/receipts', [
                'supplier_id' => $supplierId,
                'items' => [[
                    'lot_id' => $lotId,
                    'quantity' => 4,
                    'unit_cost' => 2.25,
                ]],
            ])
            ->assertOk();

        $voucherResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk();

        DB::table('tenant_activity_logs')->insert([
            'tenant_id' => 20,
            'user_id' => $admin->id,
            'domain' => 'sales',
            'event_type' => 'sales.sale.completed',
            'aggregate_type' => 'sale',
            'aggregate_id' => 999,
            'summary' => 'Venta ajena',
            'metadata' => json_encode(['reference' => 'FOREIGN'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $timelineResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/audit/timeline?limit=10');

        $timelineResponse->assertOk()
            ->assertJsonPath('data.0.event_type', 'billing.voucher.issued')
            ->assertJsonPath('data.0.aggregate_id', $voucherResponse->json('data.id'))
            ->assertJsonPath('data.0.metadata.sale_id', $saleId)
            ->assertJsonPath('data.1.event_type', 'purchasing.receipt.received')
            ->assertJsonPath('data.2.event_type', 'cash.movement.created')
            ->assertJsonPath('data.3.event_type', 'sales.sale.completed')
            ->assertJsonPath('data.4.event_type', 'cash.session.opened')
            ->assertJsonMissing(['summary' => 'Venta ajena']);

        $cashTimeline = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/audit/timeline?domain=cash&limit=10');

        $cashTimeline->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event_type', 'cash.movement.created')
            ->assertJsonPath('data.1.event_type', 'cash.session.opened');

        $activityId = $timelineResponse->json('data.0.id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/audit/timeline/{$activityId}")
            ->assertOk()
            ->assertJsonPath('data.event_type', 'billing.voucher.issued')
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.metadata.type', 'boleta');
    }

    public function test_cashier_cannot_read_audit_timeline(): void
    {
        $this->seedBaseCatalog();
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/audit/timeline')
            ->assertStatus(403);
    }

    public function test_foreign_tenant_cannot_read_activity_detail(): void
    {
        $this->seedBaseCatalog();
        $admin10 = $this->seedUserWithRole(10, 'ADMIN');
        $admin20 = $this->seedUserWithRole(20, 'ADMIN');

        $activityId = DB::table('tenant_activity_logs')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin10->id,
            'domain' => 'cash',
            'event_type' => 'cash.session.opened',
            'aggregate_type' => 'cash_session',
            'aggregate_id' => 1,
            'summary' => 'Caja abierta tenant 10',
            'metadata' => json_encode(['opening_amount' => 100], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin20)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/audit/timeline/{$activityId}")
            ->assertStatus(404);
    }

    private function seedBaseCatalog(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);
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
