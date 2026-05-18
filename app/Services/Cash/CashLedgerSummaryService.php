<?php

namespace App\Services\Cash;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CashLedgerSummaryService
{
    public function expectedAmountForSession(int $tenantId, int $cashSessionId): float
    {
        $session = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $cashSessionId)
            ->first(['id', 'opening_amount']);

        if ($session === null) {
            throw new HttpException(404, 'Cash session not found.');
        }

        $totals = DB::table('cash_session_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $cashSessionId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE 0 END), 0) as in_total,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as out_total
            ")
            ->first();

        return round(
            (float) $session->opening_amount
            + (float) ($totals->in_total ?? 0)
            - (float) ($totals->out_total ?? 0),
            2
        );
    }
}
