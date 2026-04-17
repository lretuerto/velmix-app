<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingReconciliationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reconcile_pending_voucher_and_promote_it_to_accepted(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->seedTenantAdminUser(10);
        [$voucherId, $eventId] = $this->seedPendingVoucherWithPayload(10, 1);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson(sprintf('/billing/vouchers/%d/reconcile', $voucherId), [
                'simulate_result' => 'accepted',
            ])
            ->assertOk()
            ->assertJsonPath('data.aggregate_type', 'electronic_voucher')
            ->assertJsonPath('data.aggregate_id', $voucherId)
            ->assertJsonPath('data.document.status', 'accepted');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
        ]);
    }

    private function seedTenantAdminUser(int $tenantId): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', 'ADMIN')->value('id');

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

    private function seedPendingVoucherWithPayload(int $tenantId, int $number): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => User::factory()->create()->id,
            'reference' => sprintf('SALE-RECON-%d-%d', $tenantId, $number),
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 75,
            'gross_cost' => 30,
            'gross_margin' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => $number,
            'status' => 'pending',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('billing_document_payloads')->insert([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'voucher',
            'document_number' => 'B001-1',
            'payload_hash' => hash('sha256', '{}'),
            'payload' => json_encode([
                'document' => [
                    'id' => $voucherId,
                    'series' => 'B001',
                    'number' => $number,
                ],
            ], JSON_THROW_ON_ERROR),
            'created_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $voucherId,
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$voucherId, $eventId];
    }
}
