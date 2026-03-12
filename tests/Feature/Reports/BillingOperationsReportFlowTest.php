<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingOperationsReportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_billing_operations_report_for_current_tenant(): void
    {
        $admin = $this->seedUserWithRole(10, 'ADMIN');
        $this->seedUserWithRole(20, 'ADMIN');

        DB::table('billing_provider_profiles')->insert([
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'environment' => 'live',
            'default_outcome' => 'accepted',
            'credentials' => null,
            'health_status' => 'healthy',
            'health_checked_at' => '2026-03-12 08:00:00',
            'health_message' => 'Provider fake_sunat is reachable in live mode.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $acceptedDay10 = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-10',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-10 09:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-10 09:05:00',
            providerEnvironment: 'live',
        );

        $failedDay11 = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-11',
            voucherStatus: 'failed',
            eventStatus: 'failed',
            createdAt: '2026-03-11 10:00:00',
            attemptStatus: 'failed',
            attemptCreatedAt: '2026-03-11 10:12:00',
            providerEnvironment: 'sandbox',
        );

        $acceptedDay12 = $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-12',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-12 11:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-12 11:04:00',
            providerEnvironment: 'live',
        );

        $this->seedVoucherEvent(
            tenantId: 10,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-12-REPLAY',
            voucherStatus: 'pending',
            eventStatus: 'pending',
            createdAt: '2026-03-12 12:00:00',
            attemptStatus: null,
            attemptCreatedAt: null,
            providerEnvironment: 'live',
            replayedFromEventId: $failedDay11['event_id'],
        );

        $this->seedVoucherEvent(
            tenantId: 20,
            userId: $admin->id,
            reference: 'SALE-BILL-OPS-OTHER',
            voucherStatus: 'accepted',
            eventStatus: 'processed',
            createdAt: '2026-03-12 09:00:00',
            attemptStatus: 'accepted',
            attemptCreatedAt: '2026-03-12 09:03:00',
            providerEnvironment: 'live',
        );

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-operations?date=2026-03-12&days=3&failure_limit=2')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.executive_summary.health_status', 'healthy')
            ->assertJsonPath('data.executive_summary.health_is_stale', false)
            ->assertJsonPath('data.executive_summary.pending_backlog_count', 1)
            ->assertJsonPath('data.executive_summary.failed_backlog_count', 1)
            ->assertJsonPath('data.executive_summary.acceptance_rate', 50)
            ->assertJsonPath('data.executive_summary.replay_backlog_count', 1)
            ->assertJsonPath('data.backlog_aging.total_pending_count', 1)
            ->assertJsonPath('data.backlog_aging.replay_pending_count', 1)
            ->assertJsonCount(3, 'data.trend')
            ->assertJsonPath('data.trend.0.date', '2026-03-10')
            ->assertJsonPath('data.trend.0.acceptance_rate', 100)
            ->assertJsonPath('data.trend.1.date', '2026-03-11')
            ->assertJsonPath('data.trend.1.failed_event_count', 1)
            ->assertJsonPath('data.trend.2.date', '2026-03-12')
            ->assertJsonPath('data.trend.2.event_count', 2)
            ->assertJsonPath('data.trend.2.replay_created_count', 1)
            ->assertJsonPath('data.worst_day.date', '2026-03-11')
            ->assertJsonPath('data.recent_failures.0.event_id', $failedDay11['event_id'])
            ->assertJsonFragment([
                'provider_environment' => 'live',
                'attempt_count' => 2,
                'accepted_count' => 2,
                'failed_count' => 0,
                'acceptance_rate' => 100.0,
            ])
            ->assertJsonFragment([
                'provider_environment' => 'sandbox',
                'attempt_count' => 1,
                'accepted_count' => 0,
                'failed_count' => 1,
                'acceptance_rate' => 0.0,
            ])
            ->assertJsonFragment(['code' => 'failed_backlog'])
            ->assertJsonFragment(['code' => 'replay_backlog']);
    }

    public function test_cashier_cannot_read_billing_operations_report(): void
    {
        $cashier = $this->seedUserWithRole(10, 'CAJERO');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/reports/billing-operations')
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

    private function seedVoucherEvent(
        int $tenantId,
        int $userId,
        string $reference,
        string $voucherStatus,
        string $eventStatus,
        string $createdAt,
        ?string $attemptStatus,
        ?string $attemptCreatedAt,
        string $providerEnvironment,
        ?int $replayedFromEventId = null,
    ): array {
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => 25.00,
            'gross_cost' => 10.00,
            'gross_margin' => 15.00,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => $tenantId,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => random_int(1, 9999),
            'status' => $voucherStatus,
            'sunat_ticket' => $attemptStatus === 'accepted' ? 'SUNAT-'.$reference : null,
            'rejection_reason' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
            'created_at' => $createdAt,
            'updated_at' => $attemptCreatedAt ?? $createdAt,
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => $tenantId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $voucherId,
                'provider_code' => 'fake_sunat',
                'provider_environment' => $providerEnvironment,
            ], JSON_THROW_ON_ERROR),
            'status' => $eventStatus,
            'retry_count' => $attemptStatus === 'failed' ? 1 : 0,
            'last_error' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
            'replayed_from_event_id' => $replayedFromEventId,
            'created_at' => $createdAt,
            'updated_at' => $attemptCreatedAt ?? $createdAt,
        ]);

        if ($attemptStatus !== null && $attemptCreatedAt !== null) {
            DB::table('outbox_attempts')->insert([
                'outbox_event_id' => $eventId,
                'status' => $attemptStatus,
                'provider_code' => 'fake_sunat',
                'provider_environment' => $providerEnvironment,
                'provider_reference' => 'SUNAT-'.$reference,
                'sunat_ticket' => $attemptStatus === 'accepted' ? 'SUNAT-'.$reference : null,
                'error_message' => $attemptStatus === 'failed' ? 'Provider timeout.' : null,
                'created_at' => $attemptCreatedAt,
                'updated_at' => $attemptCreatedAt,
            ]);
        }

        return [
            'sale_id' => $saleId,
            'voucher_id' => $voucherId,
            'event_id' => $eventId,
        ];
    }
}
