<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalDataPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_data_prune_command_reports_candidates_in_pretend_mode(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $userId = User::factory()->create()->id;

        $this->seedOldOperationalRows($userId);

        $exitCode = Artisan::call('platform:prune-operational-data', [
            '--pretend' => true,
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($output['pretend']);
        $this->assertSame(4, $output['total_pruned_count']);
        $this->assertDatabaseCount('idempotency_keys', 1);
        $this->assertDatabaseCount('outbox_attempts', 1);
        $this->assertDatabaseCount('tenant_user_invitations', 1);
        $this->assertDatabaseCount('operations_control_tower_snapshots', 1);
    }

    public function test_operational_data_prune_command_deletes_old_retained_rows(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $userId = User::factory()->create()->id;

        $this->seedOldOperationalRows($userId);

        $exitCode = Artisan::call('platform:prune-operational-data', [
            '--json' => true,
        ]);

        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(4, $output['total_pruned_count']);
        $this->assertDatabaseCount('idempotency_keys', 0);
        $this->assertDatabaseCount('outbox_attempts', 0);
        $this->assertDatabaseCount('tenant_user_invitations', 0);
        $this->assertDatabaseCount('operations_control_tower_snapshots', 0);
    }

    private function seedOldOperationalRows(int $userId): void
    {
        DB::table('idempotency_keys')->insert([
            'tenant_id' => 10,
            'method' => 'POST',
            'path' => '/billing/vouchers',
            'idempotency_key' => 'old-key',
            'request_hash' => hash('sha256', 'payload'),
            'status' => 'completed',
            'locked_until' => null,
            'response_status' => 200,
            'response_headers' => json_encode(['x' => ['y']], JSON_THROW_ON_ERROR),
            'response_body' => '{"ok":true}',
            'created_at' => now()->subDays(30),
            'updated_at' => now()->subDays(30),
        ]);

        $outboxEventId = DB::table('outbox_events')->insertGetId([
            'tenant_id' => 10,
            'aggregate_type' => 'electronic_voucher',
            'aggregate_id' => 7001,
            'event_type' => 'voucher_issued',
            'payload' => json_encode(['document_number' => 'B001-007001'], JSON_THROW_ON_ERROR),
            'status' => 'accepted',
            'created_at' => now()->subDays(220),
            'updated_at' => now()->subDays(220),
        ]);

        DB::table('outbox_attempts')->insert([
            'outbox_event_id' => $outboxEventId,
            'status' => 'accepted',
            'sunat_ticket' => 'SUNAT-OLD-7001',
            'error_message' => null,
            'created_at' => now()->subDays(220),
            'updated_at' => now()->subDays(220),
        ]);

        DB::table('tenant_user_invitations')->insert([
            'tenant_id' => 10,
            'email' => 'old-invite@velmix.test',
            'name' => 'Invitacion Antigua',
            'invited_by_user_id' => $userId,
            'accepted_by_user_id' => $userId,
            'status' => 'accepted',
            'pending_guard' => null,
            'token_hash' => hash('sha256', 'old-invite-token'),
            'role_codes' => json_encode(['CAJERO'], JSON_THROW_ON_ERROR),
            'expires_at' => now()->subDays(100),
            'accepted_at' => now()->subDays(99),
            'revoked_at' => null,
            'revoke_reason' => null,
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        DB::table('operations_control_tower_snapshots')->insert([
            'tenant_id' => 10,
            'user_id' => $userId,
            'snapshot_date' => now()->subDays(120)->toDateString(),
            'label' => 'snapshot-antiguo',
            'overall_status' => 'warning',
            'critical_gate_count' => 1,
            'warning_gate_count' => 2,
            'sales_completed_total' => 10,
            'collections_total' => 5,
            'cash_discrepancy_total' => 0,
            'billing_pending_backlog_count' => 1,
            'billing_failed_backlog_count' => 0,
            'finance_overdue_total' => 4,
            'finance_broken_promise_count' => 0,
            'operations_open_alert_count' => 2,
            'operations_critical_alert_count' => 1,
            'payload' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);
    }
}
