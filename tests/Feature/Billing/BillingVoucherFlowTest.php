<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingVoucherFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_voucher_and_outbox_event_from_sale(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $user = $this->createBillingUserForTenant(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk()
            ->assertJsonPath('data.series', 'B001')
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.status', 'pending');

        $voucherId = DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');
        $outboxPayload = json_decode((string) DB::table('outbox_events')->where('aggregate_id', $voucherId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'event_type' => 'voucher.created',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('billing_document_payloads', [
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'provider_code' => 'fake_sunat',
            'provider_environment' => 'sandbox',
            'schema_version' => 'fake_sunat.v1',
            'document_kind' => 'voucher',
            'document_number' => 'B001-1',
        ]);
        $this->assertSame('fake_sunat.v1', $outboxPayload['schema_version']);
        $this->assertSame('voucher', $outboxPayload['document_kind']);
        $this->assertSame('B001-1', $outboxPayload['document_number']);
        $this->assertArrayHasKey('billing_payload_id', $outboxPayload);
        $this->assertArrayHasKey('document_payload', $outboxPayload);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $user->id,
            'domain' => 'billing',
            'event_type' => 'billing.voucher.issued',
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
        ]);
    }

    public function test_reads_voucher_payload_snapshots_for_current_tenant(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $user = $this->createBillingUserForTenant(10, 'ADMIN');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk();

        $voucherId = (int) DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/billing/vouchers/{$voucherId}/payloads")
            ->assertOk()
            ->assertJsonPath('data.0.aggregate_type', 'electronic_voucher')
            ->assertJsonPath('data.0.provider_code', 'fake_sunat')
            ->assertJsonPath('data.0.schema_version', 'fake_sunat.v1')
            ->assertJsonPath('data.0.document_kind', 'voucher')
            ->assertJsonPath('data.0.document_number', 'B001-1');
    }

    public function test_can_regenerate_and_replay_failed_voucher_payload_with_new_provider_profile(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $admin = $this->createBillingUserForTenant(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk();

        $voucherId = (int) DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');
        $originalEventId = (int) DB::table('outbox_events')
            ->where('aggregate_type', 'electronic_voucher')
            ->where('aggregate_id', $voucherId)
            ->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->putJson('/billing/provider-profile', [
                'provider_code' => 'fake_sunat',
                'environment' => 'live',
                'default_outcome' => 'accepted',
            ])
            ->assertOk();

        $regenerated = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/billing/vouchers/{$voucherId}/payloads/regenerate")
            ->assertOk()
            ->assertJsonPath('data.provider_environment', 'live')
            ->assertJsonPath('data.schema_version', 'fake_sunat.v1')
            ->assertJsonPath('data.synced_event_ids.0', $originalEventId)
            ->json('data');

        $syncedPayload = json_decode((string) DB::table('outbox_events')->where('id', $originalEventId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('live', $syncedPayload['provider_environment']);
        $this->assertSame($regenerated['id'], $syncedPayload['billing_payload_id']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->putJson('/billing/provider-profile', [
                'provider_code' => 'fake_sunat',
                'environment' => 'sandbox',
                'default_outcome' => 'accepted',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch', [
                'simulate_result' => 'transient_fail',
            ])
            ->assertStatus(503)
            ->assertJsonPath('data.provider_environment', 'live');

        $replayResponse = $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/billing/vouchers/{$voucherId}/replay")
            ->assertOk()
            ->assertJsonPath('data.document_id', $voucherId)
            ->assertJsonPath('data.replayed_from_event_id', $originalEventId)
            ->json('data');

        $replayEventId = (int) $replayResponse['event_id'];
        $replayPayload = json_decode((string) DB::table('outbox_events')->where('id', $replayEventId)->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $replayEventId,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
            'status' => 'pending',
            'replayed_from_event_id' => $originalEventId,
        ]);

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'pending',
            'sunat_ticket' => null,
        ]);

        $this->assertSame('live', $replayPayload['provider_environment']);
        $this->assertSame($regenerated['id'], $replayPayload['billing_payload_id']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.event_id', $replayEventId)
            ->assertJsonPath('data.provider_environment', 'live');

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'domain' => 'billing',
            'event_type' => 'billing.payload.regenerated',
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $voucherId,
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'domain' => 'billing',
            'event_type' => 'billing.outbox.replayed',
            'aggregate_type' => 'outbox_event',
            'aggregate_id' => $replayEventId,
        ]);
    }

    public function test_rejects_replay_for_accepted_voucher(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $admin = $this->createBillingUserForTenant(10, 'ADMIN');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk();

        $voucherId = (int) DB::table('electronic_vouchers')->where('sale_id', $saleId)->value('id');

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/billing/vouchers/{$voucherId}/replay")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Accepted billing documents cannot be replayed.');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertSame(
            1,
            DB::table('outbox_events')
                ->where('aggregate_type', 'electronic_voucher')
                ->where('aggregate_id', $voucherId)
                ->count(),
        );
    }

    public function test_rejects_voucher_creation_for_sale_from_other_tenant(): void
    {
        $saleId = $this->createSaleForTenant(20);
        $user = $this->createBillingUserForTenant(10, 'CAJERO');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertStatus(404);
    }

    public function test_allocates_next_voucher_number_from_existing_documents_without_reusing_numbers(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $admin = $this->createBillingUserForTenant(10, 'ADMIN');

        $previousSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'reference' => 'SALE-VOUCHER-PREVIOUS',
            'status' => 'completed',
            'total_amount' => 40.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('electronic_vouchers')->insert([
            'tenant_id' => 10,
            'sale_id' => $previousSaleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 41,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-PREV-41',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $admin->id,
            'reference' => 'SALE-VOUCHER-NEXT',
            'status' => 'completed',
            'total_amount' => 55.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/vouchers', [
                'sale_id' => $saleId,
                'type' => 'boleta',
            ])
            ->assertOk()
            ->assertJsonPath('data.series', 'B001')
            ->assertJsonPath('data.number', 42);

        $this->assertDatabaseHas('billing_document_sequences', [
            'tenant_id' => 10,
            'document_type' => 'electronic_voucher',
            'series' => 'B001',
            'current_number' => 42,
        ]);
    }

    public function test_rejects_credit_note_creation_when_sale_has_no_voucher(): void
    {
        $saleId = $this->createSaleForTenant(10);
        $user = $this->createBillingUserForTenant(10, 'ADMIN');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/credit-notes', [
                'sale_id' => $saleId,
                'reason' => 'Intento sin comprobante',
            ])
            ->assertStatus(422);
    }

    private function createSaleForTenant(int $tenantId): int
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $userId = User::factory()->create()->id;

        return DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reference' => 'SALE-BASE-'.$tenantId,
            'status' => 'completed',
            'total_amount' => 50.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBillingUserForTenant(int $tenantId, string $roleCode): User
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
