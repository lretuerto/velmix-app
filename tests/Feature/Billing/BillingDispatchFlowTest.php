<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingDispatchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_pending_voucher_and_marks_it_accepted(): void
    {
        [$user, $voucherId, $eventId] = $this->seedPendingVoucherScenario();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.document_id', $voucherId)
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'tenant_id' => 10,
            'aggregate_id' => $voucherId,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
        ]);
    }

    public function test_marks_voucher_rejected_when_dispatch_is_rejected(): void
    {
        [$user, $voucherId, $eventId] = $this->seedPendingVoucherScenario();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch', [
                'simulate_result' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('data.document_id', $voucherId)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'rejected',
        ]);
    }

    public function test_can_retry_failed_outbox_and_dispatch_successfully(): void
    {
        [$user, $voucherId, $eventId] = $this->seedPendingVoucherScenario();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch', [
                'simulate_result' => 'transient_fail',
            ])
            ->assertStatus(503);

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'failed',
            'retry_count' => 1,
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'failed',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson("/billing/outbox/{$eventId}/retry")
            ->assertOk()
            ->assertJsonPath('data.event_id', $eventId)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.document_id', $voucherId)
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('electronic_vouchers', [
            'id' => $voucherId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_attempts', [
            'outbox_event_id' => $eventId,
            'status' => 'accepted',
        ]);
    }

    public function test_dispatches_pending_credit_note_and_marks_it_accepted(): void
    {
        [$user, $creditNoteId, $eventId] = $this->seedPendingCreditNoteScenario();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch')
            ->assertOk()
            ->assertJsonPath('data.document_id', $creditNoteId)
            ->assertJsonPath('data.event_type', 'credit_note.created')
            ->assertJsonPath('data.status', 'processed');

        $this->assertDatabaseHas('sale_credit_notes', [
            'id' => $creditNoteId,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'processed',
        ]);
    }

    public function test_dispatch_batch_processes_multiple_pending_events(): void
    {
        [$user, $voucherId, $eventId] = $this->seedPendingVoucherScenario();

        $secondSaleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => User::factory()->create()->id,
            'reference' => 'SALE-DISPATCH-10-BATCH',
            'status' => 'completed',
            'total_amount' => 44.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondVoucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $secondSaleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 2,
            'status' => 'pending',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => $secondVoucherId,
            'event_type' => 'voucher.created',
            'payload' => json_encode(['voucher_id' => $secondVoucherId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', '10')
            ->postJson('/billing/outbox/dispatch', [
                'limit' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.processed_count', 2)
            ->assertJsonPath('data.status_counts.processed', 2);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $secondEventId,
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('tenant_activity_logs', [
            'tenant_id' => 10,
            'domain' => 'billing',
            'event_type' => 'billing.outbox.dispatch_processed',
            'aggregate_type' => 'outbox_event',
            'aggregate_id' => $eventId,
        ]);
    }

    private function seedPendingVoucherScenario(): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $saleUserId = User::factory()->create()->id;

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'reference' => 'SALE-DISPATCH-10',
            'status' => 'completed',
            'total_amount' => 88.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
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
            'tenant_id' => 10,
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

    private function seedPendingCreditNoteScenario(): array
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('code', 'ADMIN')->value('id');
        $saleUserId = User::factory()->create()->id;

        DB::table('tenant_user')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'role_id' => $adminRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $saleUserId,
            'reference' => 'SALE-DISPATCH-CN-10',
            'status' => 'credited',
            'total_amount' => 88.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $voucherId = DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B001',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-CN-OK',
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $creditNoteId = DB::table('sale_credit_notes')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'electronic_voucher_id' => $voucherId,
            'series' => 'NC01',
            'number' => 1,
            'status' => 'pending',
            'reason' => 'Devolucion',
            'total_amount' => 88.00,
            'refunded_amount' => 88.00,
            'refund_payment_method' => 'cash',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'sale_credit_note',
            'aggregate_id' => $creditNoteId,
            'event_type' => 'credit_note.created',
            'payload' => json_encode(['credit_note_id' => $creditNoteId], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'retry_count' => 0,
            'last_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $creditNoteId, $eventId];
    }
}
