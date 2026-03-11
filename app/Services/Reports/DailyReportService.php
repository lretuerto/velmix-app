<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DailyReportService
{
    public function summary(int $tenantId, ?string $date = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $start = $date !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $end = $start->addDay();

        $completedSales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_amount), 0) as aggregate_total')
            ->first();

        $cancelledSales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('status', 'cancelled')
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $start)
            ->where('cancelled_at', '<', $end)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total_amount), 0) as aggregate_total')
            ->first();

        $voucherCounts = DB::table('electronic_vouchers')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->selectRaw("
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            ")
            ->first();

        $cashOpened = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('opened_at', '>=', $start)
            ->where('opened_at', '<', $end)
            ->count();

        $cashClosed = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(discrepancy_amount), 0) as discrepancy_total')
            ->first();

        return [
            'tenant_id' => $tenantId,
            'date' => $start->toDateString(),
            'sales' => [
                'completed_count' => (int) ($completedSales->aggregate_count ?? 0),
                'completed_total' => round((float) ($completedSales->aggregate_total ?? 0), 2),
                'cancelled_count' => (int) ($cancelledSales->aggregate_count ?? 0),
                'cancelled_total' => round((float) ($cancelledSales->aggregate_total ?? 0), 2),
            ],
            'vouchers' => [
                'pending_count' => (int) ($voucherCounts->pending_count ?? 0),
                'accepted_count' => (int) ($voucherCounts->accepted_count ?? 0),
                'rejected_count' => (int) ($voucherCounts->rejected_count ?? 0),
                'failed_count' => (int) ($voucherCounts->failed_count ?? 0),
            ],
            'cash' => [
                'opened_count' => (int) $cashOpened,
                'closed_count' => (int) ($cashClosed->aggregate_count ?? 0),
                'discrepancy_total' => round((float) ($cashClosed->discrepancy_total ?? 0), 2),
            ],
        ];
    }
}
