<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingReadFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_voucher_detail_for_current_tenant(): void
    {
        [$user, $voucherId, $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-000123',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/vouchers/{$voucherId}")
            ->assertOk()
            ->assertJsonPath('data.id', $voucherId)
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.outbox_event_id', $eventId);
    }

    public function test_rejects_voucher_detail_from_other_tenant(): void
    {
        [$user, $voucherId] = $this->seedVoucherScenario(10, 'ADMIN');
        $foreignUser = $this->seedBillingUser(20, 'ADMIN');

        $this->actingAs($foreignUser)
            ->withHeader('X-Tenant-Id', '20')
            ->getJson("/billing/vouchers/{$voucherId}")
            ->assertStatus(404);
    }

    public function test_reads_outbox_attempt_history_for_current_tenant(): void
    {
        [$user, , $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        DB::table('outbox_attempts')->insert([
            [
                'outbox_event_id' => $eventId,
                'status' => 'failed',
                'sunat_ticket' => null,
                'error_message' => 'Temporary transport failure.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'outbox_event_id' => $eventId,
                'status' => 'accepted',
                'sunat_ticket' => 'SUNAT-000123',
                'error_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/outbox/{$eventId}/attempts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.1.status', 'accepted');
    }

    public function test_reads_outbox_queue_summary_for_current_tenant(): void
    {
        [$user, $voucherId, $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        $processedSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => User::factory()->create()->id,
            'reference' => 'SALE-READ-PROCESSED-10',
            'status' => 'completed',
            'total_amount' => 55.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processedVoucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $processedSaleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 2,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-READY',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $processedEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $processedVoucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $processedVoucherId], JSON_THROW_ON_ERROR),
            'status' => 'processed',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $processedEventId,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-READY',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failedSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => User::factory()->create()->id,
            'reference' => 'SALE-READ-FAILED-10',
            'status' => 'completed',
            'total_amount' => 60.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failedVoucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $failedSaleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 3,
            'status' => 'failed',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_events')->insert([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $failedVoucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $failedVoucherId], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'retry_count' => 1,
            'last_error' => 'Temporary transport failure.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/billing/outbox/summary')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.oldest_pending.event_id', $eventId)
            ->assertJsonPath('data.latest_attempt.event_id', $processedEventId)
            ->assertJsonPath('data.latest_attempt.status', 'accepted')
            ->assertJsonPath('data.oldest_pending.aggregate_id', $voucherId);
    }

    private function seedVoucherScenario(int $tenantId, string $roleCode): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = $this->seedBillingUser($tenantId, $roleCode);
        $saleUserId = User::factory()->create()->id;

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $saleUserId,
            'reference' => 'SALE-READ-'.$tenantId,
            'status' => 'completed',
            'total_amount' => 42.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'pending',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $voucherId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $voucherId, $eventId];
    }

    private function seedBillingUser(int $tenantId, string $roleCode): User
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
