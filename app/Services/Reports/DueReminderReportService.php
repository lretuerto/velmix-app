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
            ]);

        $receivableFollowUps = $this->latestReceivableFollowUps(
            $tenantId,
            $receivables->pluck('id')->all(),
        );

        $receivables = $receivables
            ->map(fn (object $receivable) => $this->formatDueItem(
                'receivable',
                $receivable,
                $today,
                [
                    'customer_id' => $receivable->customer_id,
                    'customer_name' => $receivable->customer_name,
                    'sale_id' => $receivable->sale_id,
                    'sale_reference' => $receivable->sale_reference,
                    'latest_follow_up' => $receivableFollowUps[$receivable->id] ?? null,
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
            ]);

        $payableFollowUps = $this->latestPayableFollowUps(
            $tenantId,
            $payables->pluck('id')->all(),
        );

        $payables = $payables
            ->map(fn (object $payable) => $this->formatDueItem(
                'payable',
                $payable,
                $today,
                [
                    'supplier_id' => $payable->supplier_id,
                    'supplier_name' => $payable->supplier_name,
                    'purchase_receipt_id' => $payable->purchase_receipt_id,
                    'receipt_reference' => $payable->receipt_reference,
                    'latest_follow_up' => $payableFollowUps[$payable->id] ?? null,
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

    private function latestReceivableFollowUps(int $tenantId, array $receivableIds): array
    {
        if ($receivableIds === []) {
            return [];
        }

        $latest = [];

        $followUps = DB::table('sale_receivable_follow_ups')
            ->join('users', 'users.id', '=', 'sale_receivable_follow_ups.user_id')
            ->where('sale_receivable_follow_ups.tenant_id', $tenantId)
            ->whereIn('sale_receivable_follow_ups.sale_receivable_id', $receivableIds)
            ->orderByDesc('sale_receivable_follow_ups.id')
            ->get([
                'sale_receivable_follow_ups.id',
                'sale_receivable_follow_ups.sale_receivable_id',
                'sale_receivable_follow_ups.type',
                'sale_receivable_follow_ups.note',
                'sale_receivable_follow_ups.promised_amount',
                'sale_receivable_follow_ups.promised_at',
                'sale_receivable_follow_ups.created_at',
                'users.id as user_id',
                'users.name as user_name',
            ]);

        foreach ($followUps as $followUp) {
            if (! array_key_exists($followUp->sale_receivable_id, $latest)) {
                $latest[$followUp->sale_receivable_id] = $this->formatFollowUp($followUp);
            }
        }

        return $latest;
    }

    private function latestPayableFollowUps(int $tenantId, array $payableIds): array
    {
        if ($payableIds === []) {
            return [];
        }

        $latest = [];

        $followUps = DB::table('purchase_payable_follow_ups')
            ->join('users', 'users.id', '=', 'purchase_payable_follow_ups.user_id')
            ->where('purchase_payable_follow_ups.tenant_id', $tenantId)
            ->whereIn('purchase_payable_follow_ups.purchase_payable_id', $payableIds)
            ->orderByDesc('purchase_payable_follow_ups.id')
            ->get([
                'purchase_payable_follow_ups.id',
                'purchase_payable_follow_ups.purchase_payable_id',
                'purchase_payable_follow_ups.type',
                'purchase_payable_follow_ups.note',
                'purchase_payable_follow_ups.promised_amount',
                'purchase_payable_follow_ups.promised_at',
                'purchase_payable_follow_ups.created_at',
                'users.id as user_id',
                'users.name as user_name',
            ]);

        foreach ($followUps as $followUp) {
            if (! array_key_exists($followUp->purchase_payable_id, $latest)) {
                $latest[$followUp->purchase_payable_id] = $this->formatFollowUp($followUp);
            }
        }

        return $latest;
    }

    private function formatFollowUp(object $followUp): array
    {
        return [
            'id' => $followUp->id,
            'type' => $followUp->type,
            'note' => $followUp->note,
            'promised_amount' => $followUp->promised_amount !== null ? (float) $followUp->promised_amount : null,
            'promised_at' => $followUp->promised_at,
            'created_at' => $followUp->created_at,
            'user' => [
                'id' => $followUp->user_id,
                'name' => $followUp->user_name,
            ],
        ];
    }
}
