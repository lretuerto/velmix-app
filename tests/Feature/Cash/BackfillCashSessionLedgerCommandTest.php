<?php

namespace Tests\Feature\Cash;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillCashSessionLedgerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_candidates_without_mutating_cash_ledger_or_sales(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);

        $this->seedCashMovement($user, $sessionId, 'manual_in', 15.00, 'LEGACY-IN-001', $openedAt->copy()->addMinutes(5));
        $saleId = $this->seedCashSale($user, 'LEGACY-SALE-001', 25.00, $openedAt->copy()->addMinutes(10));

        $exitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($output['dry_run']);
        $this->assertSame(2, $output['created_count']);
        $this->assertSame(1, $output['updated_sales_count']);
        $this->assertSame(0, $output['unresolved_count']);
        $this->assertDatabaseCount('cash_session_ledger_entries', 0);
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'cash_session_id' => null,
        ]);
    }

    public function test_backfills_legacy_cash_movements_and_cash_sales_idempotently(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);

        $movementId = $this->seedCashMovement($user, $sessionId, 'manual_in', 15.00, 'LEGACY-IN-002', $openedAt->copy()->addMinutes(5));
        $saleId = $this->seedCashSale($user, 'LEGACY-SALE-002', 25.00, $openedAt->copy()->addMinutes(10));

        $firstExitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--json' => true,
        ]);
        $firstOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $firstExitCode);
        $this->assertSame(2, $firstOutput['created_count']);
        $this->assertSame(1, $firstOutput['updated_sales_count']);
        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'source_type' => 'cash_movement',
            'source_id' => $movementId,
            'entry_type' => 'manual_in',
            'direction' => 'in',
            'amount' => 15.00,
            'reference' => 'LEGACY-IN-002',
        ]);
        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'source_type' => 'sale',
            'source_id' => $saleId,
            'entry_type' => 'sale_cash_in',
            'direction' => 'in',
            'amount' => 25.00,
            'reference' => 'LEGACY-SALE-002',
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'cash_session_id' => $sessionId,
        ]);

        $secondExitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--json' => true,
        ]);
        $secondOutput = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $secondExitCode);
        $this->assertSame(0, $secondOutput['created_count']);
        $this->assertSame(2, $secondOutput['skipped_count']);
        $this->assertDatabaseCount('cash_session_ledger_entries', 2);
    }

    public function test_backfills_cash_receivable_payments_and_credit_note_refunds(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();
        $sessionId = $this->seedClosedCashSession($user, $openedAt, $closedAt);

        $paymentId = $this->seedCashReceivablePayment($user, 'RECEIVABLE-CASH-001', 12.00, $openedAt->copy()->addMinutes(15));
        $refundId = $this->seedCashRefund($user, $sessionId, 'REFUND-CASH-001', 8.00, $openedAt->copy()->addMinutes(20));

        $exitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $output['created_count']);
        $this->assertSame(0, $output['unresolved_count']);
        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'source_type' => 'sale_receivable_payment',
            'source_id' => $paymentId,
            'entry_type' => 'receivable_cash_in',
            'direction' => 'in',
            'amount' => 12.00,
            'reference' => 'RECEIVABLE-CASH-001',
        ]);
        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => 10,
            'cash_session_id' => $sessionId,
            'source_type' => 'sale_refund',
            'source_id' => $refundId,
            'entry_type' => 'credit_note_refund',
            'direction' => 'out',
            'amount' => 8.00,
            'reference' => 'REFUND-CASH-001',
        ]);
    }

    public function test_ambiguous_cash_sale_session_is_reported_without_mutation(): void
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);
        $user = User::factory()->create();
        $openedAt = now()->subHours(2);
        $closedAt = now()->subHour();

        $this->seedClosedCashSession($user, $openedAt, $closedAt);
        $this->seedClosedCashSession($user, $openedAt->copy()->addMinutes(10), $closedAt->copy()->addMinutes(10));
        $saleId = $this->seedCashSale($user, 'LEGACY-SALE-AMBIGUOUS', 30.00, $openedAt->copy()->addMinutes(20));

        $exitCode = Artisan::call('cash:backfill-session-ledger', [
            '--tenant' => 10,
            '--json' => true,
        ]);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $output['unresolved_count']);
        $this->assertSame('sale', $output['unresolved'][0]['source_type']);
        $this->assertSame($saleId, $output['unresolved'][0]['source_id']);
        $this->assertSame('no_unambiguous_cash_session', $output['unresolved'][0]['reason']);
        $this->assertDatabaseCount('cash_session_ledger_entries', 0);
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'cash_session_id' => null,
        ]);
    }

    private function seedClosedCashSession(
        User $user,
        mixed $openedAt,
        mixed $closedAt
    ): int {
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

    private function seedCashMovement(
        User $user,
        int $sessionId,
        string $type,
        float $amount,
        string $reference,
        mixed $createdAt
    ): int {
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

    private function seedCashSale(User $user, string $reference, float $amount, mixed $createdAt): int
    {
        return (int) DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'customer_id' => null,
            'cash_session_id' => null,
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

    private function seedCashReceivablePayment(User $user, string $reference, float $amount, mixed $paidAt): int
    {
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'customer_id' => $this->seedCustomer('DNI', '70000001', 'Cliente Cartera'),
            'cash_session_id' => null,
            'reference' => 'SALE-'.$reference,
            'status' => 'completed',
            'payment_method' => 'credit',
            'total_amount' => $amount,
            'gross_cost' => 0.00,
            'gross_margin' => $amount,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        $receivableId = (int) DB::table('sale_receivables')->insertGetId([
            'tenant_id' => 10,
            'customer_id' => DB::table('sales')->where('id', $saleId)->value('customer_id'),
            'sale_id' => $saleId,
            'total_amount' => $amount,
            'paid_amount' => $amount,
            'outstanding_amount' => 0.00,
            'status' => 'paid',
            'due_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);

        return (int) DB::table('sale_receivable_payments')->insertGetId([
            'sale_receivable_id' => $receivableId,
            'user_id' => $user->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'reference' => $reference,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }

    private function seedCashRefund(User $user, int $sessionId, string $reference, float $amount, mixed $createdAt): int
    {
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => 10,
            'user_id' => $user->id,
            'customer_id' => null,
            'cash_session_id' => null,
            'reference' => 'SALE-'.$reference,
            'status' => 'credited',
            'payment_method' => 'card',
            'total_amount' => $amount,
            'gross_cost' => 0.00,
            'gross_margin' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $voucherId = (int) DB::table('electronic_vouchers')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'type' => 'boleta',
            'series' => 'B901',
            'number' => 1,
            'status' => 'accepted',
            'sunat_ticket' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $creditNoteId = (int) DB::table('sale_credit_notes')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'electronic_voucher_id' => $voucherId,
            'series' => 'BC91',
            'number' => 1,
            'status' => 'accepted',
            'reason' => 'Devolucion de prueba',
            'total_amount' => $amount,
            'refunded_amount' => $amount,
            'refund_payment_method' => 'cash',
            'sunat_ticket' => null,
            'rejection_reason' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return (int) DB::table('sale_refunds')->insertGetId([
            'tenant_id' => 10,
            'sale_id' => $saleId,
            'sale_credit_note_id' => $creditNoteId,
            'cash_session_id' => $sessionId,
            'user_id' => $user->id,
            'payment_method' => 'cash',
            'amount' => $amount,
            'reference' => $reference,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function seedCustomer(string $documentType, string $documentNumber, string $name): int
    {
        return (int) DB::table('customers')->insertGetId([
            'tenant_id' => 10,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'name' => $name,
            'phone' => null,
            'email' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
