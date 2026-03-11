<?php

namespace App\Services\Cash;

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
                'opened_at' => $openedAt,
                'closed_at' => null,
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ]);

            return [
                'id' => $sessionId,
                'tenant_id' => $tenantId,
                'status' => 'open',
                'opening_amount' => round($openingAmount, 2),
                'opened_at' => $openedAt->toISOString(),
            ];
        });
    }

    public function current(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();

        if ($session === null) {
            throw new HttpException(404, 'No open cash session.');
        }

        return $this->buildSummary($tenantId, $session);
    }

    public function close(int $tenantId, int $userId, float $countedAmount): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($countedAmount < 0) {
            throw new HttpException(422, 'Counted amount must be valid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $countedAmount) {
            $session = DB::table('cash_sessions')
                ->where('tenant_id', $tenantId)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                throw new HttpException(404, 'No open cash session.');
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
                    'closed_at' => $closedAt,
                    'updated_at' => $closedAt,
                ]);

            return [
                'id' => $session->id,
                'tenant_id' => $tenantId,
                'status' => 'closed',
                'opening_amount' => round((float) $session->opening_amount, 2),
                'sales_count' => $summary['sales_count'],
                'sales_total' => $summary['sales_total'],
                'cash_sales_total' => $summary['cash_sales_total'],
                'card_sales_total' => $summary['card_sales_total'],
                'transfer_sales_total' => $summary['transfer_sales_total'],
                'gross_cost_total' => $summary['gross_cost_total'],
                'gross_margin_total' => $summary['gross_margin_total'],
                'margin_pct' => $summary['margin_pct'],
                'expected_amount' => round($expectedAmount, 2),
                'counted_amount' => round($countedAmount, 2),
                'discrepancy_amount' => $discrepancy,
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
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get([
                'id',
                'tenant_id',
                'status',
                'opening_amount',
                'expected_amount',
                'counted_amount',
                'discrepancy_amount',
                'opened_at',
                'closed_at',
            ])
            ->map(function (object $session) use ($tenantId) {
                $summary = $this->buildSummary($tenantId, $session);

                return [
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
                    'gross_cost_total' => $summary['gross_cost_total'],
                    'gross_margin_total' => $summary['gross_margin_total'],
                    'margin_pct' => $summary['margin_pct'],
                    'opened_at' => $session->opened_at,
                    'closed_at' => $session->closed_at,
                ];
            })
            ->all();
    }

    public function detail(int $tenantId, int $sessionId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $session = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('id', $sessionId)
            ->first();

        if ($session === null) {
            throw new HttpException(404, 'Cash session not found.');
        }

        $summary = $this->buildSummary($tenantId, $session);

        return [
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
            'gross_cost_total' => $summary['gross_cost_total'],
            'gross_margin_total' => $summary['gross_margin_total'],
            'margin_pct' => $summary['margin_pct'],
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
        ];
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
                COALESCE(SUM(CASE WHEN payment_method = 'transfer' THEN total_amount ELSE 0 END), 0) as transfer_sales_total
            ")
            ->first();

        $salesCount = (int) ($sales->sales_count ?? 0);
        $salesTotal = round((float) ($sales->sales_total ?? 0), 2);
        $grossCostTotal = round((float) ($sales->gross_cost_total ?? 0), 2);
        $grossMarginTotal = round((float) ($sales->gross_margin_total ?? 0), 2);
        $cashSalesTotal = round((float) ($sales->cash_sales_total ?? 0), 2);
        $cardSalesTotal = round((float) ($sales->card_sales_total ?? 0), 2);
        $transferSalesTotal = round((float) ($sales->transfer_sales_total ?? 0), 2);
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
            'gross_cost_total' => $grossCostTotal,
            'gross_margin_total' => $grossMarginTotal,
            'margin_pct' => $salesTotal > 0 ? round(($grossMarginTotal / $salesTotal) * 100, 2) : 0.0,
            'expected_amount' => round($openingAmount + $cashSalesTotal, 2),
            'opened_at' => $session->opened_at,
        ];
    }
}
