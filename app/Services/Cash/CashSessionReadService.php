<?php

namespace App\Services\Cash;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CashSessionReadService
{
    public function current(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = $this->sessionQuery($tenantId)
            ->where('cash_sessions.status', 'open')
            ->first($this->sessionColumns());

        if ($session === null) {
            throw new HttpException(404, 'No open cash session.');
        }

        return $this->formatSession($tenantId, $session);
    }

    public function history(int $tenantId, ?int $cursor = null, int $limit = 50): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $limit = max(1, min($limit, 100));
        $query = $this->sessionQuery($tenantId)
            ->orderByDesc('cash_sessions.id')
            ->limit($limit);

        if ($cursor !== null && $cursor > 0) {
            $query->where('cash_sessions.id', '<', $cursor);
        }

        $sessions = $query->get($this->sessionColumns())->all();
        $summaries = $this->summariesForSessions($tenantId, $sessions);

        return array_map(
            fn (object $session) => $this->formatSession($tenantId, $session, false, $summaries[(int) $session->id] ?? null),
            $sessions
        );
    }

    public function detail(int $tenantId, int $sessionId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = $this->sessionQuery($tenantId)
            ->where('cash_sessions.id', $sessionId)
            ->first($this->sessionColumns());

        if ($session === null) {
            throw new HttpException(404, 'Cash session not found.');
        }

        return $this->formatSession($tenantId, $session);
    }

    public function ledger(int $tenantId, int $sessionId, ?int $cursor = null, int $limit = 50): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $sessionExists = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->exists();

        if (! $sessionExists) {
            throw new HttpException(404, 'Cash session not found.');
        }

        $limit = max(1, min($limit, 100));
        $query = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $sessionId)
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($cursor !== null && $cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->get([
            'id',
            'source_type',
            'source_id',
            'entry_type',
            'direction',
            'amount',
            'reference',
            'notes',
            'created_by_user_id',
            'occurred_at',
            'created_at',
        ]);

        $items = $rows->take($limit)
            ->map(fn (object $entry) => [
                'id' => (int) $entry->id,
                'source_type' => $entry->source_type,
                'source_id' => (int) $entry->source_id,
                'entry_type' => $entry->entry_type,
                'direction' => $entry->direction,
                'amount' => round((float) $entry->amount, 2),
                'reference' => $entry->reference,
                'notes' => $entry->notes,
                'created_by_user_id' => (int) $entry->created_by_user_id,
                'occurred_at' => $entry->occurred_at,
                'created_at' => $entry->created_at,
            ])
            ->values()
            ->all();

        $nextCursor = $rows->count() > $limit && $items !== []
            ? (int) $items[array_key_last($items)]['id']
            : null;

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    public function summaryForSession(int $tenantId, object $session): array
    {
        return $this->summariesForSessions($tenantId, [$session])[(int) $session->id];
    }

    private function formatSession(int $tenantId, object $session, bool $includeDenominations = true, ?array $summary = null): array
    {
        $summary ??= $this->summaryForSession($tenantId, $session);

        $payload = [
            'id' => (int) $session->id,
            'tenant_id' => (int) $session->tenant_id,
            'status' => $session->status,
            'opening_amount' => round((float) $session->opening_amount, 2),
            'expected_amount' => $summary['expected_amount'],
            'counted_amount' => $session->counted_amount !== null ? round((float) $session->counted_amount, 2) : null,
            'discrepancy_amount' => $session->discrepancy_amount !== null ? round((float) $session->discrepancy_amount, 2) : null,
            'sales_count' => $summary['sales_count'],
            'sales_total' => $summary['sales_total'],
            'cash_sales_total' => $summary['cash_sales_total'],
            'card_sales_total' => $summary['card_sales_total'],
            'transfer_sales_total' => $summary['transfer_sales_total'],
            'credit_sales_total' => $summary['credit_sales_total'],
            'receivable_cash_total' => $summary['receivable_cash_total'],
            'refund_out_total' => $summary['refund_out_total'],
            'manual_in_total' => $summary['manual_in_total'],
            'manual_out_total' => $summary['manual_out_total'],
            'net_movement_total' => $summary['net_movement_total'],
            'movement_count' => $summary['movement_count'],
            'gross_cost_total' => $summary['gross_cost_total'],
            'gross_margin_total' => $summary['gross_margin_total'],
            'margin_pct' => $summary['margin_pct'],
            'opened_by' => [
                'id' => (int) $session->opened_by_user_id,
                'name' => $session->opened_by_name ?? null,
            ],
            'closed_by' => $session->closed_by_user_id !== null ? [
                'id' => (int) $session->closed_by_user_id,
                'name' => $session->closed_by_name ?? null,
            ] : null,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
        ];

        if ($includeDenominations) {
            $payload['denominations'] = $this->denominations($tenantId, (int) $session->id);
        }

        return $payload;
    }

    private function summariesForSessions(int $tenantId, array $sessions): array
    {
        $ids = array_values(array_unique(array_map(fn (object $session) => (int) $session->id, $sessions)));

        if ($ids === []) {
            return [];
        }

        $summaries = [];

        foreach ($sessions as $session) {
            $sessionId = (int) $session->id;
            $openingAmount = round((float) $session->opening_amount, 2);

            $summaries[$sessionId] = [
                'opening_amount' => $openingAmount,
                'sales_count' => 0,
                'sales_total' => 0.0,
                'cash_sales_total' => 0.0,
                'card_sales_total' => 0.0,
                'transfer_sales_total' => 0.0,
                'credit_sales_total' => 0.0,
                'receivable_cash_total' => 0.0,
                'refund_out_total' => 0.0,
                'manual_in_total' => 0.0,
                'manual_out_total' => 0.0,
                'net_movement_total' => 0.0,
                'movement_count' => 0,
                'gross_cost_total' => 0.0,
                'gross_margin_total' => 0.0,
                'margin_pct' => 0.0,
                'expected_amount' => $openingAmount,
            ];
        }

        $ledgerRows = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->whereIn('cash_session_id', $ids)
            ->selectRaw("
                cash_session_id,
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as in_total,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as out_total,
                COUNT(CASE WHEN entry_type IN ('manual_in', 'manual_out', 'receivable_cash_in', 'credit_note_refund') THEN 1 END) as movement_count,
                COALESCE(SUM(CASE WHEN entry_type = 'manual_in' THEN amount ELSE 0 END), 0) as manual_in_total,
                COALESCE(SUM(CASE WHEN entry_type = 'manual_out' THEN amount ELSE 0 END), 0) as manual_out_total,
                COALESCE(SUM(CASE WHEN entry_type = 'receivable_cash_in' THEN amount ELSE 0 END), 0) as receivable_cash_total,
                COALESCE(SUM(CASE WHEN entry_type = 'credit_note_refund' THEN amount ELSE 0 END), 0) as refund_out_total
            ")
            ->groupBy('cash_session_id')
            ->get();

        foreach ($ledgerRows as $row) {
            $sessionId = (int) $row->cash_session_id;

            if (! isset($summaries[$sessionId])) {
                continue;
            }

            $manualInTotal = round((float) $row->manual_in_total, 2);
            $manualOutTotal = round((float) $row->manual_out_total, 2);
            $receivableCashTotal = round((float) $row->receivable_cash_total, 2);
            $refundOutTotal = round((float) $row->refund_out_total, 2);

            $summaries[$sessionId]['movement_count'] = (int) $row->movement_count;
            $summaries[$sessionId]['manual_in_total'] = $manualInTotal;
            $summaries[$sessionId]['manual_out_total'] = $manualOutTotal;
            $summaries[$sessionId]['receivable_cash_total'] = $receivableCashTotal;
            $summaries[$sessionId]['refund_out_total'] = $refundOutTotal;
            $summaries[$sessionId]['net_movement_total'] = round($manualInTotal + $receivableCashTotal - $manualOutTotal - $refundOutTotal, 2);
            $summaries[$sessionId]['expected_amount'] = round(
                $summaries[$sessionId]['opening_amount'] + (float) $row->in_total - (float) $row->out_total,
                2
            );
        }

        $salesRows = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->whereIn('cash_session_id', $ids)
            ->where('status', 'completed')
            ->selectRaw("
                cash_session_id,
                COUNT(*) as sales_count,
                COALESCE(SUM(total_amount), 0) as sales_total,
                COALESCE(SUM(gross_cost), 0) as gross_cost_total,
                COALESCE(SUM(gross_margin), 0) as gross_margin_total,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales_total
            ")
            ->groupBy('cash_session_id')
            ->get();

        foreach ($salesRows as $row) {
            $sessionId = (int) $row->cash_session_id;

            if (! isset($summaries[$sessionId])) {
                continue;
            }

            $salesTotal = round((float) $row->sales_total, 2);
            $grossMarginTotal = round((float) $row->gross_margin_total, 2);

            $summaries[$sessionId]['sales_count'] = (int) $row->sales_count;
            $summaries[$sessionId]['sales_total'] = $salesTotal;
            $summaries[$sessionId]['cash_sales_total'] = round((float) $row->cash_sales_total, 2);
            $summaries[$sessionId]['card_sales_total'] = round((float) $row->card_sales_total, 2);
            $summaries[$sessionId]['transfer_sales_total'] = round((float) $row->transfer_sales_total, 2);
            $summaries[$sessionId]['credit_sales_total'] = round((float) $row->credit_sales_total, 2);
            $summaries[$sessionId]['gross_cost_total'] = round((float) $row->gross_cost_total, 2);
            $summaries[$sessionId]['gross_margin_total'] = $grossMarginTotal;
            $summaries[$sessionId]['margin_pct'] = $salesTotal > 0 ? round(($grossMarginTotal / $salesTotal) * 100, 2) : 0.0;
        }

        return $summaries;
    }

    private function sessionQuery(int $tenantId): \Illuminate\Database\Query\Builder
    {
        return DB::table('cash_sessions')
            ->leftJoin('users as opened_by', 'opened_by.id', '=', 'cash_sessions.opened_by_user_id')
            ->leftJoin('users as closed_by', 'closed_by.id', '=', 'cash_sessions.closed_by_user_id')
            ->where('cash_sessions.tenant_id', $tenantId);
    }

    private function sessionColumns(): array
    {
        return [
            'cash_sessions.*',
            'opened_by.name as opened_by_name',
            'closed_by.name as closed_by_name',
        ];
    }

    private function denominations(int $tenantId, int $sessionId): array
    {
        return DB::table('cash_session_denominations')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $sessionId)
            ->orderByDesc('value')
            ->get(['value', 'quantity', 'subtotal'])
            ->map(fn (object $denomination) => [
                'value' => round((float) $denomination->value, 2),
                'quantity' => (int) $denomination->quantity,
                'subtotal' => round((float) $denomination->subtotal, 2),
            ])
            ->all();
    }
}
