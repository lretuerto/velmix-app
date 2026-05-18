<?php

namespace Tests\Feature\Cash;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashLedgerAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_passes_after_cash_ledger_backfill_reconciles_sources(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);

        $this->seedCashMovement($user, $sessionId, 'manual_in', 15.00, 'AUDIT-IN-001', $openedAt->copy()->addMinutes(5));
        $this->seedCashSale($user, $sessionId, 'AUDIT-SALE-001', 25.00, $openedAt->copy()->addMinutes(10), withoutSession: true);

        $backfillExitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--json' => true,
        ]);
        $auditExitCode = Artisan::call('cash:audit-session-ledger', [
            '--tenant' => 10,
            '--fail-on-issues' => true,
            '--json' => true,
        ]);
        $auditOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $backfillExitCode);
        $this->assertSame(0, $auditExitCode);
        $this->assertSame('ok', $auditOutput['status']);
        $this->assertSame(0, $auditOutput['issue_count']);
    }

    public function test_audit_reports_missing_cash_sale_ledger_as_release_blocker(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);
        $saleId = $this->seedCashSale($user, $sessionId, 'AUDIT-SALE-MISSING', 30.00, $openedAt->copy()->addMinutes(10));

        $exitCode = Artisan::call('cash:audit-session-ledger', [
            '--tenant' => 10,
            '--fail-on-issues' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame(1, $output['issue_count']);
        $this->assertSame(1, $output['checks']['cash_sales']);
        $this->assertSame('missing_cash_sale_ledger', $output['issues'][0]['code']);
        $this->assertSame($saleId, $output['issues'][0]['source_id']);
    }

    public function test_audit_reports_mismatched_cash_sale_ledger_amount_or_direction(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);
        $saleId = $this->seedCashSale($user, $sessionId, 'AUDIT-SALE-MISMATCH', 30.00, $openedAt->copy()->addMinutes(10));

        DB::table('cash_session_ledger_entries')->insert([
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'source_type' => 'sale',
            'source_id' => $saleId,
            'entry_type' => 'sale_cash_in',
            'direction' => 'out',
            'amount' => 29.00,
            'reference' => 'AUDIT-SALE-MISMATCH',
            'notes' => 'Corrupt fixture',
            'created_by_user_id' => $user->id,
            'occurred_at' => $openedAt->copy()->addMinutes(10),
            'created_at' => $openedAt->copy()->addMinutes(10),
            'updated_at' => $openedAt->copy()->addMinutes(10),
        ]);

        $exitCode = Artisan::call('cash:audit-session-ledger', [
            '--tenant' => 10,
            '--fail-on-issues' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('critical', $output['status']);
        $this->assertSame('mismatched_cash_sale_ledger', $output['issues'][0]['code']);
        $this->assertSame('in', $output['issues'][0]['expected_direction']);
        $this->assertSame('out', $output['issues'][0]['actual_direction']);
        $this->assertSame(30, $output['issues'][0]['expected_amount']);
        $this->assertSame(29, $output['issues'][0]['actual_amount']);
    }

    private function seedClosedCashSession(User $user, mixed $openedAt, mixed $closedAt): int
    {
        return (int) DB::table('cash_sessions')->insertGetId([
            'tenant_id' => 10,
            'opened_by_user_id' => $user->id,
            'closed_by_user_id' => $user->id,
            'opening_amount' => 100.00,
            'expected_amount' => 100.00,
            'counted_amount' => 100.00,
            'discrepancy_amount' => 0.00,
            'status' => 'closed',
            'open_guard' => null,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
            'created_at' => $openedAt,
            'updated_at' => $closedAt,
        ]);
    }

    private function seedCashMovement(User $user, int $sessionId, string $type, float $amount, string $reference, mixed $createdAt): int
    {
        return (int) DB::table('cash_movements')->insertGetId([
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'created_by_user_id' => $user->id,
            'type' => $type,
            'amount' => $amount,
            'reference' => $reference,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedCashSale(
        User $user,
        int $sessionId,
        string $reference,
        float $amount,
        mixed $createdAt,
        bool $withoutSession = false
    ): int {
        return (int) DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'customer_id' => null,
            'cash_session_id' => $withoutSession ? null : $sessionId,
            'reference' => $reference,
            'status' => 'completed',
            'payment_method' => 'cash',
            'total_amount' => $amount,
            'gross_cost' => 0.00,
            'gross_margin' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
