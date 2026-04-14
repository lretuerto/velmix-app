<?php

namespace App\Services\Cash;

use App\Services\Audit\TenantActivityLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CashSessionService
{
    public function open(int $tenantId, int $userId, float $openingAmount): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($openingAmount < 0) {
            throw new HttpException(422, 'Opening amount must be valid.');
        }

        try {
            return DB::transaction(function () use ($tenantId, $userId, $openingAmount) {
                $existing = DB::table('cash_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw new HttpException(422, 'There is already an open cash session.');
                }

                $openedAt = now();

                $sessionId = DB::table('cash_sessions')->insertGetId([
                    'tenant_id' => $tenantId,
                    'opened_by_user_id' => $userId,
                    'closed_by_user_id' => null,
                    'opening_amount' => $openingAmount,
                    'expected_amount' => $openingAmount,
                    'counted_amount' => null,
                    'discrepancy_amount' => null,
                    'status' => 'open',
                    'open_guard' => $this->openGuardForTenant($tenantId),
                    'opened_at' => $openedAt,
                    'closed_at' => null,
                    'created_at' => $openedAt,
                    'updated_at' => $openedAt,
                ]);

                app(TenantActivityLogService::class)->record(
                    $tenantId,
                    $userId,
                    'cash',
                    'cash.session.opened',
                    'cash_session',
                    $sessionId,
                    'Caja abierta',
                    [
                        'cash_session_id' => $sessionId,
                        'opening_amount' => round($openingAmount, 2),
                    ],
                    $openedAt->toISOString(),
                );

                return [
                    'id' => $sessionId,
                    'tenant_id' => $tenantId,
                    'status' => 'open',
                    'opening_amount' => round($openingAmount, 2),
                    'opened_at' => $openedAt->toISOString(),
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isOpenGuardConflict($exception)) {
                throw new HttpException(422, 'There is already an open cash session.');
            }

            throw $exception;
        }
    }

    public function current(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = DB::table('cash_sessions')
            ->leftJoin('users as opened_by', 'opened_by.id', '=', 'cash_sessions.opened_by_user_id')
            ->leftJoin('users as closed_by', 'closed_by.id', '=', 'cash_sessions.closed_by_user_id')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first([
                'cash_sessions.*',
                'opened_by.name as opened_by_name',
                'closed_by.name as closed_by_name',
            ]);

        if ($session === null) {
            throw new HttpException(404, 'No open cash session.');
        }

        return $this->enrichSession($tenantId, $session);
    }

    public function close(int $tenantId, int $userId, ?float $countedAmount = null, array $denominations = []): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($countedAmount !== null && $countedAmount < 0) {
            throw new HttpException(422, 'Counted amount must be valid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $countedAmount, $denominations) {
            $session = DB::table('cash_sessions')
                ->leftJoin('users as opened_by', 'opened_by.id', '=', 'cash_sessions.opened_by_user_id')
                ->where('tenant_id', $tenantId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first([
                    'cash_sessions.*',
                    'opened_by.name as opened_by_name',
                ]);

            if ($session === null) {
                throw new HttpException(404, 'No open cash session.');
            }

            $normalizedDenominations = $this->normalizeDenominations($denominations);
            $denominationCountedAmount = $this->sumDenominations($normalizedDenominations);

            if ($countedAmount === null && $normalizedDenominations === []) {
                throw new HttpException(422, 'counted_amount or denominations are required.');
            }

            if ($countedAmount === null) {
                $countedAmount = $denominationCountedAmount;
            }

            if (
                $normalizedDenominations !== []
                && abs($countedAmount - $denominationCountedAmount) > 0.009
            ) {
                throw new HttpException(422, 'Counted amount does not match denominations total.');
            }

            $summary = $this->buildSummary($tenantId, $session);
            $expectedAmount = (float) $summary['expected_amount'];
            $discrepancy = round($countedAmount - $expectedAmount, 2);
            $closedAt = now();

            DB::table('cash_sessions')
                ->where('id', $session->id)
                ->update([
                    'closed_by_user_id' => $userId,
                    'expected_amount' => $expectedAmount,
                    'counted_amount' => $countedAmount,
                    'discrepancy_amount' => $discrepancy,
                    'status' => 'closed',
                    'open_guard' => null,
                    'closed_at' => $closedAt,
                    'updated_at' => $closedAt,
                ]);

            if ($normalizedDenominations !== []) {
                DB::table('cash_session_denominations')
                    ->where('cash_session_id', $session->id)
                    ->delete();

                DB::table('cash_session_denominations')->insert(array_map(
                    fn (array $denomination) => [
                        'tenant_id' => $tenantId,
                        'cash_session_id' => $session->id,
                        'value' => $denomination['value'],
                        'quantity' => $denomination['quantity'],
                        'subtotal' => $denomination['subtotal'],
                        'created_at' => $closedAt,
                        'updated_at' => $closedAt,
                    ],
                    $normalizedDenominations
                ));
            }

            $closedByName = DB::table('users')->where('id', $userId)->value('name');

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'cash',
                'cash.session.closed',
                'cash_session',
                $session->id,
                'Caja cerrada',
                [
                    'cash_session_id' => $session->id,
                    'expected_amount' => round($expectedAmount, 2),
                    'counted_amount' => round($countedAmount, 2),
                    'discrepancy_amount' => $discrepancy,
                    'sales_total' => $summary['sales_total'],
                    'cash_sales_total' => $summary['cash_sales_total'],
                    'movement_count' => $summary['movement_count'],
                ],
                $closedAt->toISOString(),
            );

            return [
                'id' => $session->id,
                'tenant_id' => $tenantId,
                'status' => 'closed',
                'opening_amount' => round((float) $session->opening_amount, 2),
                'expected_amount' => round($expectedAmount, 2),
                'counted_amount' => round($countedAmount, 2),
                'discrepancy_amount' => $discrepancy,
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
                    'id' => $session->opened_by_user_id,
                    'name' => $session->opened_by_name ?? null,
                ],
                'closed_by' => [
                    'id' => $userId,
                    'name' => $closedByName,
                ],
                'denominations' => $normalizedDenominations,
                'opened_at' => $session->opened_at,
                'closed_at' => $closedAt->toISOString(),
            ];
        });
    }

    public function history(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('cash_sessions')
            ->leftJoin('users as opened_by', 'opened_by.id', '=', 'cash_sessions.opened_by_user_id')
            ->leftJoin('users as closed_by', 'closed_by.id', '=', 'cash_sessions.closed_by_user_id')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('cash_sessions.id')
            ->get([
                'cash_sessions.*',
                'opened_by.name as opened_by_name',
                'closed_by.name as closed_by_name',
            ])
            ->map(fn (object $session) => $this->enrichSession($tenantId, $session, false))
            ->all();
    }

    public function detail(int $tenantId, int $sessionId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = DB::table('cash_sessions')
            ->leftJoin('users as opened_by', 'opened_by.id', '=', 'cash_sessions.opened_by_user_id')
            ->leftJoin('users as closed_by', 'closed_by.id', '=', 'cash_sessions.closed_by_user_id')
            ->where('tenant_id', $tenantId)
            ->where('cash_sessions.id', $sessionId)
            ->first([
                'cash_sessions.*',
                'opened_by.name as opened_by_name',
                'closed_by.name as closed_by_name',
            ]);

        if ($session === null) {
            throw new HttpException(404, 'Cash session not found.');
        }

        return $this->enrichSession($tenantId, $session);
    }

    private function buildSummary(int $tenantId, object $session): array
    {
        $salesQuery = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $session->opened_at)
            ->where('status', 'completed');

        if ($session->closed_at !== null) {
            $salesQuery->where('created_at', '<=', $session->closed_at);
        }

        $sales = $salesQuery
            ->selectRaw("
                COUNT(*) as sales_count,
                COALESCE(SUM(total_amount), 0) as sales_total,
                COALESCE(SUM(gross_cost), 0) as gross_cost_total,
                COALESCE(SUM(gross_margin), 0) as gross_margin_total,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) as cash_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) as card_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_sales_total,
                COALESCE(SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END), 0) as credit_sales_total
            ")
            ->first();

        $salesCount = (int) ($sales->sales_count ?? 0);
        $salesTotal = round((float) ($sales->sales_total ?? 0), 2);
        $grossCostTotal = round((float) ($sales->gross_cost_total ?? 0), 2);
        $grossMarginTotal = round((float) ($sales->gross_margin_total ?? 0), 2);
        $cashSalesTotal = round((float) ($sales->cash_sales_total ?? 0), 2);
        $cardSalesTotal = round((float) ($sales->card_sales_total ?? 0), 2);
        $transferSalesTotal = round((float) ($sales->transfer_sales_total ?? 0), 2);
        $creditSalesTotal = round((float) ($sales->credit_sales_total ?? 0), 2);
        $movementTotals = DB::table('cash_movements')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $session->id)
            ->selectRaw("
                COUNT(*) as movement_count,
                COALESCE(SUM(CASE WHEN type = 'manual_in' THEN amount ELSE 0 END), 0) as manual_in_total,
                COALESCE(SUM(CASE WHEN type = 'manual_out' THEN amount ELSE 0 END), 0) as manual_out_total,
                COALESCE(SUM(CASE WHEN type = 'receivable_in' THEN amount ELSE 0 END), 0) as receivable_cash_total,
                COALESCE(SUM(CASE WHEN type = 'credit_note_refund' THEN amount ELSE 0 END), 0) as refund_out_total
            ")
            ->first();
        $movementCount = (int) ($movementTotals->movement_count ?? 0);
        $manualInTotal = round((float) ($movementTotals->manual_in_total ?? 0), 2);
        $manualOutTotal = round((float) ($movementTotals->manual_out_total ?? 0), 2);
        $receivableCashTotal = round((float) ($movementTotals->receivable_cash_total ?? 0), 2);
        $refundOutTotal = round((float) ($movementTotals->refund_out_total ?? 0), 2);
        $netMovementTotal = round($manualInTotal + $receivableCashTotal - $manualOutTotal - $refundOutTotal, 2);
        $openingAmount = round((float) $session->opening_amount, 2);

        return [
            'id' => $session->id,
            'tenant_id' => $tenantId,
            'status' => $session->status,
            'opening_amount' => $openingAmount,
            'sales_count' => $salesCount,
            'sales_total' => $salesTotal,
            'cash_sales_total' => $cashSalesTotal,
            'card_sales_total' => $cardSalesTotal,
            'transfer_sales_total' => $transferSalesTotal,
            'credit_sales_total' => $creditSalesTotal,
            'receivable_cash_total' => $receivableCashTotal,
            'refund_out_total' => $refundOutTotal,
            'manual_in_total' => $manualInTotal,
            'manual_out_total' => $manualOutTotal,
            'net_movement_total' => $netMovementTotal,
            'movement_count' => $movementCount,
            'gross_cost_total' => $grossCostTotal,
            'gross_margin_total' => $grossMarginTotal,
            'margin_pct' => $salesTotal > 0 ? round(($grossMarginTotal / $salesTotal) * 100, 2) : 0.0,
            'expected_amount' => round($openingAmount + $cashSalesTotal + $netMovementTotal, 2),
            'opened_at' => $session->opened_at,
        ];
    }

    private function enrichSession(int $tenantId, object $session, bool $includeDenominations = true): array
    {
        $summary = $this->buildSummary($tenantId, $session);

        $payload = [
            'id' => $session->id,
            'tenant_id' => $session->tenant_id,
            'status' => $session->status,
            'opening_amount' => (float) $session->opening_amount,
            'expected_amount' => $summary['expected_amount'],
            'counted_amount' => $session->counted_amount !== null ? (float) $session->counted_amount : null,
            'discrepancy_amount' => $session->discrepancy_amount !== null ? (float) $session->discrepancy_amount : null,
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
                'id' => $session->opened_by_user_id,
                'name' => $session->opened_by_name ?? null,
            ],
            'closed_by' => $session->closed_by_user_id !== null ? [
                'id' => $session->closed_by_user_id,
                'name' => $session->closed_by_name ?? null,
            ] : null,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
        ];

        if ($includeDenominations) {
            $payload['denominations'] = $this->getDenominations($tenantId, (int) $session->id);
        }

        return $payload;
    }

    private function openGuardForTenant(int $tenantId): string
    {
        return sprintf('tenant:%d', $tenantId);
    }

    private function isOpenGuardConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $previous = strtolower($exception->getPrevious()?->getMessage() ?? '');

        foreach ([
            'cash_sessions_open_guard_unique',
            'cash_sessions.open_guard',
            'open_guard',
            'unique constraint failed',
            'duplicate entry',
        ] as $needle) {
            if (str_contains($message, $needle) || ($previous !== '' && str_contains($previous, $needle))) {
                return true;
            }
        }

        return false;
    }

    private function getDenominations(int $tenantId, int $sessionId): array
    {
        return DB::table('cash_session_denominations')
            ->where('tenant_id', $tenantId)
            ->where('cash_session_id', $sessionId)
            ->orderByDesc('value')
            ->get(['value', 'quantity', 'subtotal'])
            ->map(fn (object $denomination) => [
                'value' => (float) $denomination->value,
                'quantity' => (int) $denomination->quantity,
                'subtotal' => (float) $denomination->subtotal,
            ])
            ->all();
    }

    private function normalizeDenominations(array $denominations): array
    {
        return collect($denominations)
            ->map(function (array $denomination) {
                $value = round((float) ($denomination['value'] ?? 0), 2);
                $quantity = (int) ($denomination['quantity'] ?? 0);

                if ($value <= 0 || $quantity <= 0) {
                    throw new HttpException(422, 'Cash denominations must be valid.');
                }

                return [
                    'value' => $value,
                    'quantity' => $quantity,
                    'subtotal' => round($value * $quantity, 2),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    private function sumDenominations(array $denominations): float
    {
        return round(collect($denominations)->sum('subtotal'), 2);
    }
}
