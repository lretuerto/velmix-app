<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReceivableRiskReportService
{
    public function summary(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $receivables = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.outstanding_amount', '>', 0)
            ->selectRaw("
                customers.id as customer_id,
                customers.name as customer_name,
                customers.credit_limit,
                COUNT(*) as receivable_count,
                COALESCE(SUM(sale_receivables.outstanding_amount), 0) as outstanding_total,
                COALESCE(SUM(CASE WHEN sale_receivables.due_at IS NOT NULL AND sale_receivables.due_at < ? THEN sale_receivables.outstanding_amount ELSE 0 END), 0) as overdue_total,
                COALESCE(SUM(CASE WHEN sale_receivables.due_at IS NULL OR sale_receivables.due_at >= ? THEN sale_receivables.outstanding_amount ELSE 0 END), 0) as current_total,
                SUM(CASE WHEN sale_receivables.due_at IS NOT NULL AND sale_receivables.due_at < ? THEN 1 ELSE 0 END) as overdue_count
            ", [now(), now(), now()])
            ->groupBy('customers.id', 'customers.name', 'customers.credit_limit')
            ->orderByDesc('overdue_total')
            ->orderByDesc('outstanding_total')
            ->get();

        $customers = $receivables
            ->map(function (object $customer) {
                $creditLimit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
                $outstandingTotal = round((float) $customer->outstanding_total, 2);
                $overdueTotal = round((float) $customer->overdue_total, 2);

                return [
                    'customer_id' => $customer->customer_id,
                    'customer_name' => $customer->customer_name,
                    'receivable_count' => (int) $customer->receivable_count,
                    'outstanding_total' => $outstandingTotal,
                    'overdue_total' => $overdueTotal,
                    'current_total' => round((float) $customer->current_total, 2),
                    'overdue_count' => (int) $customer->overdue_count,
                    'credit_limit' => $creditLimit,
                    'available_credit' => $creditLimit !== null ? round($creditLimit - $outstandingTotal, 2) : null,
                    'credit_utilization_pct' => $creditLimit !== null && $creditLimit > 0
                        ? round(($outstandingTotal / $creditLimit) * 100, 2)
                        : null,
                ];
            })
            ->values();

        return [
            'tenant_id' => $tenantId,
            'summary' => [
                'customer_count' => $customers->count(),
                'receivable_count' => (int) $customers->sum('receivable_count'),
                'outstanding_total' => round($customers->sum('outstanding_total'), 2),
                'overdue_total' => round($customers->sum('overdue_total'), 2),
                'current_total' => round($customers->sum('current_total'), 2),
            ],
            'top_overdue_customers' => $customers->filter(fn (array $customer) => $customer['overdue_total'] > 0)
                ->take(5)
                ->values()
                ->all(),
            'top_exposed_customers' => $customers->sortByDesc('outstanding_total')
                ->take(5)
                ->values()
                ->all(),
        ];
    }
}
