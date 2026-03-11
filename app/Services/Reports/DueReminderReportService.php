<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DueReminderReportService
{
    public function summary(int $tenantId, int $daysAhead = 7, int $limit = 5, ?string $baseDate = null): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if ($daysAhead < 1) {
            throw new HttpException(422, 'days_ahead must be at least 1.');
        }

        if ($limit < 1) {
            throw new HttpException(422, 'limit must be at least 1.');
        }

        $today = $baseDate !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $baseDate)->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $upcomingEnd = $today->addDays($daysAhead);

        $receivables = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.outstanding_amount', '>', 0)
            ->whereNotNull('sale_receivables.due_at')
            ->get([
                'sale_receivables.id',
                'sale_receivables.sale_id',
                'sale_receivables.outstanding_amount',
                'sale_receivables.due_at',
                'customers.id as customer_id',
                'customers.name as customer_name',
                'sales.reference as sale_reference',
            ])
            ->map(fn (object $receivable) => $this->formatDueItem(
                'receivable',
                $receivable,
                $today,
                [
                    'customer_id' => $receivable->customer_id,
                    'customer_name' => $receivable->customer_name,
                    'sale_id' => $receivable->sale_id,
                    'sale_reference' => $receivable->sale_reference,
                ],
            ));

        $payables = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.outstanding_amount', '>', 0)
            ->whereNotNull('purchase_payables.due_at')
            ->get([
                'purchase_payables.id',
                'purchase_payables.purchase_receipt_id',
                'purchase_payables.outstanding_amount',
                'purchase_payables.due_at',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ])
            ->map(fn (object $payable) => $this->formatDueItem(
                'payable',
                $payable,
                $today,
                [
                    'supplier_id' => $payable->supplier_id,
                    'supplier_name' => $payable->supplier_name,
                    'purchase_receipt_id' => $payable->purchase_receipt_id,
                    'receipt_reference' => $payable->receipt_reference,
                ],
            ));

        return [
            'tenant_id' => $tenantId,
            'date' => $today->toDateString(),
            'days_ahead' => $daysAhead,
            'receivables' => $this->buildBucketPayload($receivables, $today, $upcomingEnd, $limit),
            'payables' => $this->buildBucketPayload($payables, $today, $upcomingEnd, $limit),
        ];
    }

    private function formatDueItem(string $type, object $row, CarbonImmutable $today, array $meta): array
    {
        $dueDate = CarbonImmutable::parse($row->due_at)->startOfDay();
        $daysDiff = $today->diffInDays($dueDate, false);

        return array_merge([
            'type' => $type,
            'id' => $row->id,
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
            'due_at' => $dueDate->toDateString(),
            'days_until_due' => $daysDiff,
            'days_overdue' => $daysDiff < 0 ? abs($daysDiff) : 0,
        ], $meta);
    }

    private function buildBucketPayload(Collection $items, CarbonImmutable $today, CarbonImmutable $upcomingEnd, int $limit): array
    {
        $overdue = $items
            ->filter(fn (array $item) => CarbonImmutable::parse($item['due_at'])->lt($today))
            ->sortByDesc('days_overdue')
            ->values();

        $dueToday = $items
            ->filter(fn (array $item) => CarbonImmutable::parse($item['due_at'])->equalTo($today))
            ->sortBy('id')
            ->values();

        $upcoming = $items
            ->filter(function (array $item) use ($today, $upcomingEnd) {
                $dueDate = CarbonImmutable::parse($item['due_at']);

                return $dueDate->gt($today) && $dueDate->lte($upcomingEnd);
            })
            ->sortBy('days_until_due')
            ->values();

        return [
            'summary' => [
                'overdue_count' => $overdue->count(),
                'overdue_amount' => round($overdue->sum('outstanding_amount'), 2),
                'due_today_count' => $dueToday->count(),
                'due_today_amount' => round($dueToday->sum('outstanding_amount'), 2),
                'upcoming_count' => $upcoming->count(),
                'upcoming_amount' => round($upcoming->sum('outstanding_amount'), 2),
            ],
            'overdue' => $overdue->take($limit)->all(),
            'due_today' => $dueToday->take($limit)->all(),
            'upcoming' => $upcoming->take($limit)->all(),
        ];
    }
}
