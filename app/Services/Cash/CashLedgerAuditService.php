<?php

namespace App\Services\Cash;

use Illuminate\Support\Facades\DB;

class CashLedgerAuditService
{
    private const CHUNK_SIZE = 500;

    public function audit(?int $tenantId = null, ?int $sessionId = null, int $issueLimit = 200): array
    {
        $tenantIds = $this->tenantIds($tenantId, $sessionId);
        $summary = [
            'status' => 'ok',
            'checked_at' => now()->toIso8601String(),
            'tenant_count' => count($tenantIds),
            'session_id' => $sessionId,
            'issue_limit' => max(1, min($issueLimit, 1000)),
            'issue_count' => 0,
            'truncated' => false,
            'checks' => [
                'duplicate_source_entries' => 0,
                'unassigned_cash_sales' => 0,
                'cash_sales' => 0,
                'cash_movements' => 0,
                'receivable_payments' => 0,
                'credit_note_refunds' => 0,
            ],
            'issues' => [],
        ];

        foreach ($tenantIds as $currentTenantId) {
            $session = $sessionId !== null
                ? $this->sessionWindow($currentTenantId, $sessionId)
                : null;

            if ($sessionId !== null && $session === null) {
                continue;
            }

            $this->auditDuplicateSources($summary, $currentTenantId, $sessionId);
            $this->auditUnassignedCashSales($summary, $currentTenantId, $session);
            $this->auditCashSales($summary, $currentTenantId, $sessionId);
            $this->auditCashMovements($summary, $currentTenantId, $sessionId);
            $this->auditReceivablePayments($summary, $currentTenantId, $session);
            $this->auditCreditNoteRefunds($summary, $currentTenantId, $sessionId);
        }

        $summary['status'] = $summary['issue_count'] > 0 ? 'critical' : 'ok';

        return $summary;
    }

    private function auditDuplicateSources(array &$summary, int $tenantId, ?int $sessionId): void
    {
        $query = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->selectRaw('source_type, source_id, entry_type, COUNT(*) as duplicate_count')
            ->groupBy('source_type', 'source_id', 'entry_type')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('source_type')
            ->orderBy('source_id');

        if ($sessionId !== null) {
            $query->where('cash_session_id', $sessionId);
        }

        foreach ($query->get() as $row) {
            $this->addIssue($summary, 'duplicate_source_entries', [
                'code' => 'duplicate_cash_ledger_source',
                'severity' => 'critical',
                'tenant_id' => $tenantId,
                'source_type' => $row->source_type,
                'source_id' => (int) $row->source_id,
                'entry_type' => $row->entry_type,
                'duplicate_count' => (int) $row->duplicate_count,
            ]);
        }
    }

    private function auditUnassignedCashSales(array &$summary, int $tenantId, ?object $session): void
    {
        $query = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('payment_method', 'cash')
            ->where('status', 'completed')
            ->whereNull('cash_session_id');

        if ($session !== null) {
            $query->where('created_at', '>=', $session->opened_at);

            if ($session->closed_at !== null) {
                $query->where('created_at', '<=', $session->closed_at);
            }
        }

        foreach ($query->select(['id', 'reference', 'total_amount', 'created_at'])->lazyById(self::CHUNK_SIZE) as $sale) {
            $this->addIssue($summary, 'unassigned_cash_sales', [
                'code' => 'unassigned_cash_sale',
                'severity' => 'critical',
                'tenant_id' => $tenantId,
                'source_type' => 'sale',
                'source_id' => (int) $sale->id,
                'reference' => $sale->reference,
                'amount' => round((float) $sale->total_amount, 2),
                'occurred_at' => $sale->created_at,
            ]);
        }
    }

    private function auditCashSales(array &$summary, int $tenantId, ?int $sessionId): void
    {
        $query = DB::table('sales')
            ->leftJoin('cash_session_ledger_entries as ledger', function ($join): void {
                $join->on('ledger.source_id', '=', 'sales.id')
                    ->where('ledger.source_type', 'sale')
                    ->where('ledger.entry_type', 'sale_cash_in');
            })
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.payment_method', 'cash')
            ->where('sales.status', 'completed')
            ->whereNotNull('sales.cash_session_id');

        if ($sessionId !== null) {
            $query->where('sales.cash_session_id', $sessionId);
        }

        foreach ($query->select([
            'sales.id',
            'sales.cash_session_id',
            'sales.reference',
            'sales.total_amount',
            'ledger.id as ledger_id',
            'ledger.cash_session_id as ledger_session_id',
            'ledger.direction as ledger_direction',
            'ledger.amount as ledger_amount',
        ])->lazyById(self::CHUNK_SIZE, 'sales.id', 'id') as $sale) {
            if ($sale->ledger_id === null) {
                $this->addMissingLedgerIssue($summary, 'cash_sales', 'missing_cash_sale_ledger', $tenantId, 'sale', (int) $sale->id, $sale->reference, (float) $sale->total_amount);

                continue;
            }

            $this->assertLedgerMatches(
                $summary,
                'cash_sales',
                'mismatched_cash_sale_ledger',
                $tenantId,
                'sale',
                (int) $sale->id,
                $sale->reference,
                (int) $sale->cash_session_id,
                (int) $sale->ledger_session_id,
                'in',
                (string) $sale->ledger_direction,
                (float) $sale->total_amount,
                (float) $sale->ledger_amount,
            );
        }
    }

    private function auditCashMovements(array &$summary, int $tenantId, ?int $sessionId): void
    {
        $query = DB::table('cash_movements')
            ->leftJoin('cash_session_ledger_entries as ledger', function ($join): void {
                $join->on('ledger.source_id', '=', 'cash_movements.id')
                    ->where('ledger.source_type', 'cash_movement');
            })
            ->where('cash_movements.tenant_id', $tenantId);

        if ($sessionId !== null) {
            $query->where('cash_movements.cash_session_id', $sessionId);
        }

        foreach ($query->select([
            'cash_movements.id',
            'cash_movements.cash_session_id',
            'cash_movements.type',
            'cash_movements.reference',
            'cash_movements.amount',
            'ledger.id as ledger_id',
            'ledger.cash_session_id as ledger_session_id',
            'ledger.entry_type as ledger_entry_type',
            'ledger.direction as ledger_direction',
            'ledger.amount as ledger_amount',
        ])->lazyById(self::CHUNK_SIZE, 'cash_movements.id', 'id') as $movement) {
            $mapping = $this->movementMapping((string) $movement->type);

            if ($mapping === null || (float) $movement->amount <= 0) {
                $this->addIssue($summary, 'cash_movements', [
                    'code' => 'invalid_cash_movement_source',
                    'severity' => 'critical',
                    'tenant_id' => $tenantId,
                    'source_type' => 'cash_movement',
                    'source_id' => (int) $movement->id,
                    'reference' => $movement->reference,
                    'movement_type' => $movement->type,
                    'amount' => round((float) $movement->amount, 2),
                ]);

                continue;
            }

            if ($movement->ledger_id === null || $movement->ledger_entry_type !== $mapping['entry_type']) {
                $this->addMissingLedgerIssue($summary, 'cash_movements', 'missing_cash_movement_ledger', $tenantId, 'cash_movement', (int) $movement->id, $movement->reference, (float) $movement->amount);

                continue;
            }

            $this->assertLedgerMatches(
                $summary,
                'cash_movements',
                'mismatched_cash_movement_ledger',
                $tenantId,
                'cash_movement',
                (int) $movement->id,
                $movement->reference,
                (int) $movement->cash_session_id,
                (int) $movement->ledger_session_id,
                $mapping['direction'],
                (string) $movement->ledger_direction,
                (float) $movement->amount,
                (float) $movement->ledger_amount,
            );
        }
    }

    private function auditReceivablePayments(array &$summary, int $tenantId, ?object $session): void
    {
        $query = DB::table('sale_receivable_payments')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_payments.sale_receivable_id')
            ->leftJoin('cash_session_ledger_entries as ledger', function ($join): void {
                $join->on('ledger.source_id', '=', 'sale_receivable_payments.id')
                    ->where('ledger.source_type', 'sale_receivable_payment')
                    ->where('ledger.entry_type', 'receivable_cash_in');
            })
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivable_payments.payment_method', 'cash');

        if ($session !== null) {
            $query->where(function ($query) use ($session): void {
                $query->where('ledger.cash_session_id', $session->id)
                    ->orWhere(function ($query) use ($session): void {
                        $query->whereNull('ledger.id')
                            ->where('sale_receivable_payments.paid_at', '>=', $session->opened_at);

                        if ($session->closed_at !== null) {
                            $query->where('sale_receivable_payments.paid_at', '<=', $session->closed_at);
                        }
                    });
            });
        }

        foreach ($query->select([
            'sale_receivable_payments.id',
            'sale_receivable_payments.reference',
            'sale_receivable_payments.amount',
            'ledger.id as ledger_id',
            'ledger.cash_session_id as ledger_session_id',
            'ledger.direction as ledger_direction',
            'ledger.amount as ledger_amount',
        ])->lazyById(self::CHUNK_SIZE, 'sale_receivable_payments.id', 'id') as $payment) {
            if ($payment->ledger_id === null) {
                $this->addMissingLedgerIssue($summary, 'receivable_payments', 'missing_receivable_payment_ledger', $tenantId, 'sale_receivable_payment', (int) $payment->id, $payment->reference, (float) $payment->amount);

                continue;
            }

            $expectedSessionId = $session !== null ? (int) $session->id : (int) $payment->ledger_session_id;
            $this->assertLedgerMatches(
                $summary,
                'receivable_payments',
                'mismatched_receivable_payment_ledger',
                $tenantId,
                'sale_receivable_payment',
                (int) $payment->id,
                $payment->reference,
                $expectedSessionId,
                (int) $payment->ledger_session_id,
                'in',
                (string) $payment->ledger_direction,
                (float) $payment->amount,
                (float) $payment->ledger_amount,
            );
        }
    }

    private function auditCreditNoteRefunds(array &$summary, int $tenantId, ?int $sessionId): void
    {
        $query = DB::table('sale_refunds')
            ->leftJoin('cash_session_ledger_entries as ledger', function ($join): void {
                $join->on('ledger.source_id', '=', 'sale_refunds.id')
                    ->where('ledger.source_type', 'sale_refund')
                    ->where('ledger.entry_type', 'credit_note_refund');
            })
            ->where('sale_refunds.tenant_id', $tenantId)
            ->where('sale_refunds.payment_method', 'cash')
            ->whereNotNull('sale_refunds.cash_session_id');

        if ($sessionId !== null) {
            $query->where('sale_refunds.cash_session_id', $sessionId);
        }

        foreach ($query->select([
            'sale_refunds.id',
            'sale_refunds.cash_session_id',
            'sale_refunds.reference',
            'sale_refunds.amount',
            'ledger.id as ledger_id',
            'ledger.cash_session_id as ledger_session_id',
            'ledger.direction as ledger_direction',
            'ledger.amount as ledger_amount',
        ])->lazyById(self::CHUNK_SIZE, 'sale_refunds.id', 'id') as $refund) {
            if ($refund->ledger_id === null) {
                $this->addMissingLedgerIssue($summary, 'credit_note_refunds', 'missing_credit_note_refund_ledger', $tenantId, 'sale_refund', (int) $refund->id, $refund->reference, (float) $refund->amount);

                continue;
            }

            $this->assertLedgerMatches(
                $summary,
                'credit_note_refunds',
                'mismatched_credit_note_refund_ledger',
                $tenantId,
                'sale_refund',
                (int) $refund->id,
                $refund->reference,
                (int) $refund->cash_session_id,
                (int) $refund->ledger_session_id,
                'out',
                (string) $refund->ledger_direction,
                (float) $refund->amount,
                (float) $refund->ledger_amount,
            );
        }
    }

    private function assertLedgerMatches(
        array &$summary,
        string $check,
        string $code,
        int $tenantId,
        string $sourceType,
        int $sourceId,
        string $reference,
        int $expectedSessionId,
        int $actualSessionId,
        string $expectedDirection,
        string $actualDirection,
        float $expectedAmount,
        float $actualAmount,
    ): void {
        if (
            $expectedSessionId === $actualSessionId
            && $expectedDirection === $actualDirection
            && round($expectedAmount, 2) === round($actualAmount, 2)
        ) {
            return;
        }

        $this->addIssue($summary, $check, [
            'code' => $code,
            'severity' => 'critical',
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reference' => $reference,
            'expected_session_id' => $expectedSessionId,
            'actual_session_id' => $actualSessionId,
            'expected_direction' => $expectedDirection,
            'actual_direction' => $actualDirection,
            'expected_amount' => round($expectedAmount, 2),
            'actual_amount' => round($actualAmount, 2),
        ]);
    }

    private function addMissingLedgerIssue(array &$summary, string $check, string $code, int $tenantId, string $sourceType, int $sourceId, string $reference, float $amount): void
    {
        $this->addIssue($summary, $check, [
            'code' => $code,
            'severity' => 'critical',
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reference' => $reference,
            'amount' => round($amount, 2),
        ]);
    }

    private function addIssue(array &$summary, string $check, array $issue): void
    {
        $summary['issue_count']++;
        $summary['checks'][$check]++;

        if (count($summary['issues']) < $summary['issue_limit']) {
            $summary['issues'][] = $issue;

            return;
        }

        $summary['truncated'] = true;
    }

    private function sessionWindow(int $tenantId, int $sessionId): ?object
    {
        return DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->first(['id', 'opened_at', 'closed_at']);
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
}
