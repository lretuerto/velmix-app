<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PromiseInsightService
{
    public function latestReceivablePromises(int $tenantId, CarbonImmutable $baseDate, array $receivableIds = []): array
    {
        $followUps = $this->latestFollowUps(
            'sale_receivable_follow_ups',
            'sale_receivable_id',
            $tenantId,
            $receivableIds,
        );

        if ($followUps === []) {
            return [];
        }

        $receivables = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->whereIn('sale_receivables.id', array_keys($followUps))
            ->get([
                'sale_receivables.id',
                'sale_receivables.sale_id',
                'sale_receivables.outstanding_amount',
                'sale_receivables.due_at',
                'customers.id as customer_id',
                'customers.name as customer_name',
                'sales.reference as sale_reference',
            ])
            ->keyBy('id');

        $items = [];

        foreach ($followUps as $receivableId => $followUp) {
            $receivable = $receivables->get($receivableId);

            if ($receivable === null) {
                continue;
            }

            $items[$receivableId] = array_merge(
                $this->buildPromiseInsight($followUp, (float) $receivable->outstanding_amount, $baseDate),
                [
                    'receivable_id' => $receivable->id,
                    'sale_id' => $receivable->sale_id,
                    'sale_reference' => $receivable->sale_reference,
                    'customer_id' => $receivable->customer_id,
                    'customer_name' => $receivable->customer_name,
                    'outstanding_amount' => round((float) $receivable->outstanding_amount, 2),
                    'due_at' => $receivable->due_at,
                ],
            );
        }

        return $items;
    }

    public function latestPayablePromises(int $tenantId, CarbonImmutable $baseDate, array $payableIds = []): array
    {
        $followUps = $this->latestFollowUps(
            'purchase_payable_follow_ups',
            'purchase_payable_id',
            $tenantId,
            $payableIds,
        );

        if ($followUps === []) {
            return [];
        }

        $payables = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->whereIn('purchase_payables.id', array_keys($followUps))
            ->get([
                'purchase_payables.id',
                'purchase_payables.purchase_receipt_id',
                'purchase_payables.outstanding_amount',
                'purchase_payables.due_at',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ])
            ->keyBy('id');

        $items = [];

        foreach ($followUps as $payableId => $followUp) {
            $payable = $payables->get($payableId);

            if ($payable === null) {
                continue;
            }

            $items[$payableId] = array_merge(
                $this->buildPromiseInsight($followUp, (float) $payable->outstanding_amount, $baseDate),
                [
                    'purchase_payable_id' => $payable->id,
                    'purchase_receipt_id' => $payable->purchase_receipt_id,
                    'receipt_reference' => $payable->receipt_reference,
                    'supplier_id' => $payable->supplier_id,
                    'supplier_name' => $payable->supplier_name,
                    'outstanding_amount' => round((float) $payable->outstanding_amount, 2),
                    'due_at' => $payable->due_at,
                ],
            );
        }

        return $items;
    }

    public function escalationLevel(?string $dueAt, ?array $promise, CarbonImmutable $baseDate): string
    {
        if ($promise !== null && ($promise['status'] ?? null) === 'broken') {
            return 'critical';
        }

        if ($dueAt === null) {
            return $promise !== null && ($promise['status'] ?? null) === 'pending'
                ? 'watch'
                : 'normal';
        }

        $dueDate = CarbonImmutable::parse($dueAt)->startOfDay();

        if ($dueDate->lt($baseDate)) {
            $daysOverdue = abs($baseDate->diffInDays($dueDate, false));

            if ($daysOverdue >= 30) {
                return 'high';
            }

            if ($daysOverdue >= 7) {
                return 'medium';
            }

            return 'attention';
        }

        if ($promise !== null && ($promise['status'] ?? null) === 'pending') {
            return 'watch';
        }

        return 'normal';
    }

    private function latestFollowUps(string $table, string $entityColumn, int $tenantId, array $entityIds): array
    {
        $query = DB::table($table)
            ->join('users', 'users.id', '=', $table.'.user_id')
            ->where($table.'.tenant_id', $tenantId)
            ->where($table.'.type', 'promise')
            ->whereNotNull($table.'.promised_at')
            ->orderByDesc($table.'.id');

        if ($entityIds !== []) {
            $query->whereIn($table.'.'.$entityColumn, $entityIds);
        }

        $rows = $query->get([
            $table.'.id',
            $table.'.'.$entityColumn,
            $table.'.note',
            $table.'.promised_amount',
            $table.'.outstanding_snapshot',
            $table.'.promised_at',
            $table.'.created_at',
            'users.id as user_id',
            'users.name as user_name',
        ]);

        $latest = [];

        foreach ($rows as $row) {
            $entityId = (int) $row->{$entityColumn};

            if (! array_key_exists($entityId, $latest)) {
                $latest[$entityId] = $row;
            }
        }

        return $latest;
    }

    private function buildPromiseInsight(object $followUp, float $currentOutstandingAmount, CarbonImmutable $baseDate): array
    {
        $snapshot = $followUp->outstanding_snapshot !== null
            ? round((float) $followUp->outstanding_snapshot, 2)
            : round($currentOutstandingAmount, 2);
        $targetAmount = $followUp->promised_amount !== null
            ? round((float) $followUp->promised_amount, 2)
            : $snapshot;
        $resolvedAmount = round(max($snapshot - $currentOutstandingAmount, 0), 2);
        $promiseDate = CarbonImmutable::parse($followUp->promised_at)->startOfDay();

        if ($currentOutstandingAmount <= 0 || $resolvedAmount >= $targetAmount) {
            $status = 'fulfilled';
        } elseif ($promiseDate->gt($baseDate)) {
            $status = 'pending';
        } else {
            $status = 'broken';
        }

        $daysUntilPromise = $baseDate->diffInDays($promiseDate, false);

        return [
            'follow_up_id' => $followUp->id,
            'type' => 'promise',
            'note' => $followUp->note,
            'promised_amount' => $followUp->promised_amount !== null ? round((float) $followUp->promised_amount, 2) : null,
            'outstanding_snapshot' => $snapshot,
            'target_amount' => $targetAmount,
            'resolved_amount' => $resolvedAmount,
            'promised_at' => $promiseDate->toDateString(),
            'created_at' => $followUp->created_at,
            'status' => $status,
            'days_until_promise' => $daysUntilPromise,
            'days_past_promise' => $daysUntilPromise < 0 ? abs($daysUntilPromise) : 0,
            'user' => [
                'id' => $followUp->user_id,
                'name' => $followUp->user_name,
            ],
        ];
    }
}
