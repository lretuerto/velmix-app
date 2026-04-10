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
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'sunat_ticket' => 'SUNAT-000123',
            'provider_reference' => 'SUNAT-000123',
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
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'sandbox',
                'sunat_ticket' => null,
                'provider_reference' => null,
                'error_message' => 'Temporary transport failure.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'outbox_event_id' => $eventId,
                'status' => 'accepted',
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'sandbox',
                'sunat_ticket' => 'SUNAT-000123',
                'provider_reference' => 'SUNAT-000123',
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
            ->assertJsonPath('data.0.provider_code', 'fake_sunat')
            ->assertJsonPath('data.0.provider_environment', 'sandbox')
            ->assertJsonPath('data.1.status', 'accepted')
            ->assertJsonPath('data.1.provider_reference', 'SUNAT-000123');
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
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'sunat_ticket' => 'SUNAT-READY',
            'provider_reference' => 'SUNAT-READY',
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
            ->assertJsonPath('data.provider_profile.provider_code', 'fake_sunat')
            ->assertJsonPath('data.provider_profile.credentials', null)
            ->assertJsonPath('data.provider_profile.credentials_configured', false)
            ->assertJsonPath('data.provider_profile.health_status', 'unknown')
            ->assertJsonPath('data.oldest_pending.event_id', $eventId)
            ->assertJsonPath('data.latest_attempt.event_id', $processedEventId)
            ->assertJsonPath('data.latest_attempt.status', 'accepted')
            ->assertJsonPath('data.latest_attempt.provider_code', 'fake_sunat')
            ->assertJsonPath('data.latest_attempt.provider_environment', 'sandbox')
            ->assertJsonPath('data.latest_attempt.provider_reference', 'SUNAT-READY')
            ->assertJsonPath('data.oldest_pending.aggregate_id', $voucherId);
    }

    public function test_reads_provider_trace_for_current_tenant(): void
    {
        [$user, , $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        DB::table('billing_provider_profiles')->insert([
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'environment' => 'live',
            'default_outcome' => 'accepted',
            'credentials' => null,
            'health_status' => 'healthy',
            'health_checked_at' => now(),
            'health_message' => 'Provider fake_sunat is reachable in live mode.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'live',
            'sunat_ticket' => 'SUNAT-LIVE-001',
            'provider_reference' => 'SUNAT-LIVE-001',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/billing/outbox/provider-trace?limit=5')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', 10)
            ->assertJsonPath('data.provider_profile.environment', 'live')
            ->assertJsonPath('data.provider_profile.credentials', null)
            ->assertJsonPath('data.provider_profile.credentials_configured', false)
            ->assertJsonPath('data.provider_profile.health_status', 'healthy')
            ->assertJsonPath('data.status_breakdown.0.provider_code', 'fake_sunat')
            ->assertJsonPath('data.status_breakdown.0.provider_environment', 'live')
            ->assertJsonPath('data.status_breakdown.0.status', 'accepted')
            ->assertJsonPath('data.status_breakdown.0.attempts_count', 1)
            ->assertJsonPath('data.recent_attempts.0.provider_environment', 'live')
            ->assertJsonPath('data.recent_attempts.0.provider_reference', 'SUNAT-LIVE-001');
    }

    public function test_reads_provider_metrics_for_current_tenant(): void
    {
        [$user, $voucherId, $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        DB::table('billing_provider_profiles')->insert([
            'tenant_id' => 10,
            'provider_code' => 'fake_sunat',
            'environment' => 'live',
            'default_outcome' => 'accepted',
            'credentials' => null,
            'health_status' => 'healthy',
            'health_checked_at' => '2026-03-09 08:00:00',
            'health_message' => 'Provider fake_sunat is reachable in live mode.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_events')->where('id', $eventId)->update([
            'payload' => json_encode([
                'voucher_id' => $voucherId,
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'live',
            ], JSON_THROW_ON_ERROR),
            'status' => 'processed',
            'created_at' => '2026-03-10 08:00:00',
            'updated_at' => '2026-03-10 08:10:00',
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'live',
            'provider_reference' => 'SUNAT-LIVE-100',
            'sunat_ticket' => 'SUNAT-LIVE-100',
            'error_message' => null,
            'created_at' => '2026-03-10 08:10:00',
            'updated_at' => '2026-03-10 08:10:00',
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => User::factory()->create()->id,
            'reference' => 'SALE-METRICS-10',
            'status' => 'completed',
            'total_amount' => 70.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $failedVoucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 2,
            'status' => 'failed',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:30:00',
        ]);

        $failedEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $failedVoucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $failedVoucherId,
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'live',
            ], JSON_THROW_ON_ERROR),
            'status' => 'failed',
            'retry_count' => 1,
            'last_error' => 'Timeout contacting provider.',
            'created_at' => '2026-03-11 09:00:00',
            'updated_at' => '2026-03-11 09:30:00',
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $failedEventId,
            'status' => 'failed',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'live',
            'provider_reference' => 'SUNAT-LIVE-101',
            'sunat_ticket' => null,
            'error_message' => 'Timeout contacting provider.',
            'created_at' => '2026-03-11 09:30:00',
            'updated_at' => '2026-03-11 09:30:00',
        ]);

        $replayEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $failedVoucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $failedVoucherId,
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'live',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'replayed_from_event_id' => $failedEventId,
            'created_at' => '2026-03-11 10:00:00',
            'updated_at' => '2026-03-11 10:00:00',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson('/billing/provider-metrics?date=2026-03-11&days=2&recent_failures_limit=2')
            ->assertOk()
            ->assertJsonPath('data.window.days', 2)
            ->assertJsonPath('data.provider_profile.environment', 'live')
            ->assertJsonPath('data.provider_profile.credentials', null)
            ->assertJsonPath('data.provider_profile.credentials_configured', false)
            ->assertJsonPath('data.health.current_status', 'healthy')
            ->assertJsonPath('data.health.is_stale', true)
            ->assertJsonPath('data.queue.pending_count', 1)
            ->assertJsonPath('data.queue.failed_count', 1)
            ->assertJsonPath('data.performance.event_count', 3)
            ->assertJsonPath('data.performance.accepted_event_count', 1)
            ->assertJsonPath('data.performance.failed_event_count', 1)
            ->assertJsonPath('data.performance.pending_event_count', 1)
            ->assertJsonPath('data.replays.created_count', 1)
            ->assertJsonPath('data.replays.pending_count', 1)
            ->assertJsonPath('data.by_provider_environment.0.provider_environment', 'live')
            ->assertJsonPath('data.recent_failures.0.event_id', $failedEventId)
            ->assertJsonFragment(['code' => 'health_stale'])
            ->assertJsonFragment(['code' => 'failed_backlog'])
            ->assertJsonFragment(['code' => 'replay_backlog']);
    }

    public function test_reads_outbox_lineage_for_replayed_document(): void
    {
        [$user, $voucherId, $eventId] = $this->seedVoucherScenario(10, 'ADMIN');

        $payloadIdV1 = DB::table('billing_document_payloads')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'voucher',
            'document_number' => 'B001-1',
            'payload_hash' => str_repeat('a', 64),
            'payload' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('outbox_events')->where('id', $eventId)->update([
            'payload' => json_encode([
                'voucher_id' => $voucherId,
                'billing_payload_id' => $payloadIdV1,
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'sandbox',
                'schema_version' => 'fake_sunat.v1',
                'document_kind' => 'voucher',
                'document_number' => 'B001-1',
            ], JSON_THROW_ON_ERROR),
            'status' => 'processed',
            'updated_at' => now(),
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'provider_reference' => 'SUNAT-SBX-001',
            'sunat_ticket' => 'SUNAT-SBX-001',
            'error_message' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloadIdV2 = DB::table('billing_document_payloads')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'live',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'voucher',
            'document_number' => 'B001-1',
            'payload_hash' => str_repeat('b', 64),
            'payload' => json_encode(['version' => 2], JSON_THROW_ON_ERROR),
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $replayEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode([
                'voucher_id' => $voucherId,
                'billing_payload_id' => $payloadIdV2,
                'provider_code' => 'fake_sunat',
                'provider_environment' => 'live',
                'schema_version' => 'fake_sunat.v1',
                'document_kind' => 'voucher',
                'document_number' => 'B001-1',
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'replayed_from_event_id' => $eventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_activity_logs')->insert([
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'domain' => 'billing',
                'event_type' => 'billing.payload.regenerated',
                'aggregate_type' => 'electronic_voucher',
                'aggregate_id' => $voucherId,
                'summary' => 'Payload regenerated',
                'metadata' => json_encode(['billing_payload_id' => $payloadIdV2], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 10,
                'user_id' => $user->id,
                'domain' => 'billing',
                'event_type' => 'billing.outbox.replayed',
                'aggregate_type' => 'outbox_event',
                'aggregate_id' => $replayEventId,
                'summary' => 'Replay created',
                'metadata' => json_encode(['replayed_from_event_id' => $eventId], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/outbox/{$replayEventId}/lineage")
            ->assertOk()
            ->assertJsonPath('data.current_event_id', $replayEventId)
            ->assertJsonPath('data.root_event_id', $eventId)
            ->assertJsonPath('data.latest_event_id', $replayEventId)
            ->assertJsonPath('data.lineage.0.event_id', $eventId)
            ->assertJsonPath('data.lineage.0.replay_depth', 0)
            ->assertJsonPath('data.lineage.0.attempts.0.status', 'accepted')
            ->assertJsonPath('data.lineage.1.event_id', $replayEventId)
            ->assertJsonPath('data.lineage.1.replayed_from_event_id', $eventId)
            ->assertJsonPath('data.lineage.1.replay_depth', 1)
            ->assertJsonPath('data.lineage.1.payload_snapshot.provider_environment', 'live')
            ->assertJsonPath('data.payload_snapshots.1.provider_environment', 'live')
            ->assertJsonPath('data.activity_logs.0.event_type', 'billing.payload.regenerated')
            ->assertJsonPath('data.activity_logs.1.event_type', 'billing.outbox.replayed');
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
