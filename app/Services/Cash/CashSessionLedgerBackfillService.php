<?php

namespace App\Services\Cash;

use Illuminate\Support\Facades\DB;

class CashSessionLedgerBackfillService
{
    private const CHUNK_SIZE = 500;

    public function run(?int $tenantId = null, ?int $sessionId = null, bool $dryRun = false): array
    {
        $tenantIds = $this->tenantIds($tenantId, $sessionId);
        $summary = [
            'dry_run' => $dryRun,
            'tenant_count' => count($tenantIds),
            'created_count' => 0,
            'updated_sales_count' => 0,
            'skipped_count' => 0,
            'unresolved_count' => 0,
            'items' => [],
            'unresolved' => [],
        ];

        foreach ($tenantIds as $currentTenantId) {
            $this->backfillCashMovements($summary, $currentTenantId, $sessionId, $dryRun);
            $this->backfillSaleRefunds($summary, $currentTenantId, $sessionId, $dryRun);
            $this->backfillReceivablePayments($summary, $currentTenantId, $sessionId, $dryRun);
            $this->backfillCashSales($summary, $currentTenantId, $sessionId, $dryRun);
        }

        return $summary;
    }

    private function backfillCashMovements(array &$summary, int $tenantId, ?int $sessionId, bool $dryRun): void
    {
        $query = DB::table('cash_movements')
            ->where('tenant_id', $tenantId);

        if ($sessionId !== null) {
            $query->where('cash_session_id', $sessionId);
        }

        foreach ($query->lazyById(self::CHUNK_SIZE) as $movement) {
            $mapping = $this->movementMapping((string) $movement->type);

            if ($mapping === null || (float) $movement->amount <= 0) {
                $this->unresolved($summary, 'cash_movement', (int) $movement->id, $tenantId, 'unsupported_or_invalid_movement');

                continue;
            }

            if (
                $this->sourceLedgerExists('cash_movement', (int) $movement->id, $mapping['entry_type'])
                || $this->equivalentLedgerExists($tenantId, (int) $movement->cash_session_id, $mapping['entry_type'], (float) $movement->amount, (string) $movement->reference)
            ) {
                $this->skipped($summary, 'cash_movement', (int) $movement->id, $tenantId, 'ledger_exists');

                continue;
            }

            $this->createLedgerEntry($summary, $dryRun, [
                'tenant_id' => $tenantId,
                'cash_session_id' => (int) $movement->cash_session_id,
                'source_type' => 'cash_movement',
                'source_id' => (int) $movement->id,
                'entry_type' => $mapping['entry_type'],
                'direction' => $mapping['direction'],
                'amount' => round((float) $movement->amount, 2),
                'reference' => (string) $movement->reference,
                'notes' => $movement->notes,
                'created_by_user_id' => (int) $movement->created_by_user_id,
                'occurred_at' => $movement->created_at,
            ]);
        }
    }

    private function backfillSaleRefunds(array &$summary, int $tenantId, ?int $sessionId, bool $dryRun): void
    {
        $query = DB::table('sale_refunds')
            ->where('tenant_id', $tenantId)
            ->where('payment_method', 'cash')
            ->whereNotNull('cash_session_id');

        if ($sessionId !== null) {
            $query->where('cash_session_id', $sessionId);
        }

        foreach ($query->lazyById(self::CHUNK_SIZE) as $refund) {
            if ((float) $refund->amount <= 0) {
                $this->unresolved($summary, 'sale_refund', (int) $refund->id, $tenantId, 'invalid_refund_amount');

                continue;
            }

            if (
                $this->sourceLedgerExists('sale_refund', (int) $refund->id, 'credit_note_refund')
                || $this->equivalentLedgerExists($tenantId, (int) $refund->cash_session_id, 'credit_note_refund', (float) $refund->amount, (string) $refund->reference)
            ) {
                $this->skipped($summary, 'sale_refund', (int) $refund->id, $tenantId, 'ledger_exists');

                continue;
            }

            $this->createLedgerEntry($summary, $dryRun, [
                'tenant_id' => $tenantId,
                'cash_session_id' => (int) $refund->cash_session_id,
                'source_type' => 'sale_refund',
                'source_id' => (int) $refund->id,
                'entry_type' => 'credit_note_refund',
                'direction' => 'out',
                'amount' => round((float) $refund->amount, 2),
                'reference' => (string) $refund->reference,
                'notes' => $refund->notes,
                'created_by_user_id' => (int) $refund->user_id,
                'occurred_at' => $refund->created_at,
            ]);
        }
    }

    private function backfillReceivablePayments(array &$summary, int $tenantId, ?int $sessionId, bool $dryRun): void
    {
        $query = DB::table('sale_receivable_payments')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_payments.sale_receivable_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivable_payments.payment_method', 'cash');

        foreach ($query->select([
            'sale_receivable_payments.id',
            'sale_receivable_payments.user_id',
            'sale_receivable_payments.amount',
            'sale_receivable_payments.reference',
            'sale_receivable_payments.paid_at',
            'sale_receivable_payments.created_at',
        ])->lazyById(self::CHUNK_SIZE, 'sale_receivable_payments.id', 'id') as $payment) {
            if ((float) $payment->amount <= 0) {
                $this->unresolved($summary, 'sale_receivable_payment', (int) $payment->id, $tenantId, 'invalid_payment_amount');

                continue;
            }

            if ($this->sourceLedgerExists('sale_receivable_payment', (int) $payment->id, 'receivable_cash_in')) {
                $this->skipped($summary, 'sale_receivable_payment', (int) $payment->id, $tenantId, 'ledger_exists');

                continue;
            }

            $resolvedSessionId = $this->resolveSessionForTimestamp($tenantId, $payment->paid_at ?? $payment->created_at, $sessionId);

            if ($resolvedSessionId === null) {
                $this->unresolved($summary, 'sale_receivable_payment', (int) $payment->id, $tenantId, 'no_unambiguous_cash_session');

                continue;
            }

            if ($this->equivalentLedgerExists($tenantId, $resolvedSessionId, 'receivable_cash_in', (float) $payment->amount, (string) $payment->reference)) {
                $this->skipped($summary, 'sale_receivable_payment', (int) $payment->id, $tenantId, 'equivalent_ledger_exists');

                continue;
            }

            $this->createLedgerEntry($summary, $dryRun, [
                'tenant_id' => $tenantId,
                'cash_session_id' => $resolvedSessionId,
                'source_type' => 'sale_receivable_payment',
                'source_id' => (int) $payment->id,
                'entry_type' => 'receivable_cash_in',
                'direction' => 'in',
                'amount' => round((float) $payment->amount, 2),
                'reference' => (string) $payment->reference,
                'notes' => 'Receivable cash payment backfilled',
                'created_by_user_id' => (int) $payment->user_id,
                'occurred_at' => $payment->paid_at ?? $payment->created_at,
            ]);
        }
    }

    private function backfillCashSales(array &$summary, int $tenantId, ?int $sessionId, bool $dryRun): void
    {
        $query = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('payment_method', 'cash');

        if ($sessionId !== null) {
            $query->where(function ($query) use ($sessionId): void {
                $query->where('cash_session_id', $sessionId)
                    ->orWhereNull('cash_session_id');
            });
        }

        foreach ($query->select(['id', 'user_id', 'cash_session_id', 'reference', 'status', 'total_amount', 'created_at'])->lazyById(self::CHUNK_SIZE) as $sale) {
            if ((string) $sale->status !== 'completed') {
                $this->unresolved($summary, 'sale', (int) $sale->id, $tenantId, 'unsupported_sale_status');

                continue;
            }

            if ((float) $sale->total_amount <= 0) {
                $this->unresolved($summary, 'sale', (int) $sale->id, $tenantId, 'invalid_sale_amount');

                continue;
            }

            if ($this->sourceLedgerExists('sale', (int) $sale->id, 'sale_cash_in')) {
                $this->skipped($summary, 'sale', (int) $sale->id, $tenantId, 'ledger_exists');

                continue;
            }

            $resolvedSessionId = $sale->cash_session_id !== null
                ? (int) $sale->cash_session_id
                : $this->resolveSessionForTimestamp($tenantId, $sale->created_at, $sessionId);

            if ($resolvedSessionId === null || ! $this->sessionBelongsToTenant($tenantId, $resolvedSessionId)) {
                $this->unresolved($summary, 'sale', (int) $sale->id, $tenantId, 'no_unambiguous_cash_session');

                continue;
            }

            if ($this->equivalentLedgerExists($tenantId, $resolvedSessionId, 'sale_cash_in', (float) $sale->total_amount, (string) $sale->reference)) {
                $this->skipped($summary, 'sale', (int) $sale->id, $tenantId, 'equivalent_ledger_exists');

                continue;
            }

            if ($sale->cash_session_id === null && ! $dryRun) {
                DB::table('sales')
                    ->where('id', $sale->id)
                    ->update([
                        'cash_session_id' => $resolvedSessionId,
                        'updated_at' => now(),
                    ]);

                $summary['updated_sales_count']++;
            } elseif ($sale->cash_session_id === null) {
                $summary['updated_sales_count']++;
            }

            $this->createLedgerEntry($summary, $dryRun, [
                'tenant_id' => $tenantId,
                'cash_session_id' => $resolvedSessionId,
                'source_type' => 'sale',
                'source_id' => (int) $sale->id,
                'entry_type' => 'sale_cash_in',
                'direction' => 'in',
                'amount' => round((float) $sale->total_amount, 2),
                'reference' => (string) $sale->reference,
                'notes' => 'Cash sale backfilled',
                'created_by_user_id' => (int) $sale->user_id,
                'occurred_at' => $sale->created_at,
            ]);
        }
    }

    private function createLedgerEntry(array &$summary, bool $dryRun, array $entry): void
    {
        $payload = $entry + [
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $summary['created_count']++;
        $summary['items'][] = [
            'action' => $dryRun ? 'would_create' : 'created',
            'tenant_id' => $entry['tenant_id'],
            'cash_session_id' => $entry['cash_session_id'],
            'source_type' => $entry['source_type'],
            'source_id' => $entry['source_id'],
            'entry_type' => $entry['entry_type'],
            'direction' => $entry['direction'],
            'amount' => $entry['amount'],
            'reference' => $entry['reference'],
        ];

        if (! $dryRun) {
            DB::table('cash_session_ledger_entries')->insert($payload);
        }
    }

    private function resolveSessionForTimestamp(int $tenantId, mixed $timestamp, ?int $sessionId): ?int
    {
        if ($timestamp === null) {
            return null;
        }

        $query = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('opened_at', '<=', $timestamp)
            ->where(function ($query) use ($timestamp): void {
                $query->whereNull('closed_at')
                    ->orWhere('closed_at', '>=', $timestamp);
            })
            ->orderBy('id')
            ->limit(2);

        if ($sessionId !== null) {
            $query->where('id', $sessionId);
        }

        $matches = $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function movementMapping(string $type): ?array
    {
        return match ($type) {
            'manual_in' => ['entry_type' => 'manual_in', 'direction' => 'in'],
            'manual_out' => ['entry_type' => 'manual_out', 'direction' => 'out'],
            'receivable_in' => ['entry_type' => 'receivable_cash_in', 'direction' => 'in'],
            'credit_note_refund' => ['entry_type' => 'credit_note_refund', 'direction' => 'out'],
            default => null,
        };
    }

    private function sourceLedgerExists(string $sourceType, int $sourceId, string $entryType): bool
    {
        return DB::table('cash_session_ledger_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType)
            ->exists();
    }

    private function equivalentLedgerExists(int $tenantId, int $sessionId, string $entryType, float $amount, string $reference): bool
    {
        return DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $sessionId)
            ->where('entry_type', $entryType)
            ->where('amount', round($amount, 2))
            ->where('reference', $reference)
            ->exists();
    }

    private function sessionBelongsToTenant(int $tenantId, int $sessionId): bool
    {
        return DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->exists();
    }

    private function tenantIds(?int $tenantId, ?int $sessionId): array
    {
        if ($tenantId !== null && $tenantId > 0) {
            return [$tenantId];
        }

        if ($sessionId !== null && $sessionId > 0) {
            $sessionTenantId = DB::table('cash_sessions')->where('id', $sessionId)->value('tenant_id');

            return $sessionTenantId !== null ? [(int) $sessionTenantId] : [];
        }

        return DB::table('tenants')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function skipped(array &$summary, string $sourceType, int $sourceId, int $tenantId, string $reason): void
    {
        $summary['skipped_count']++;
        $summary['items'][] = [
            'action' => 'skipped',
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
        ];
    }

    private function unresolved(array &$summary, string $sourceType, int $sourceId, int $tenantId, string $reason): void
    {
        $summary['unresolved_count']++;
        $summary['unresolved'][] = [
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
        ];
    }
}
