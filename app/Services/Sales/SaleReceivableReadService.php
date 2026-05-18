<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleReceivableReadService
{
    public function list(int $tenantId, array $filters = []): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $limit = $this->limit($filters['limit'] ?? 100);
        $query = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->orderByDesc('sale_receivables.id')
            ->limit($limit);

        if (! empty($filters['status'])) {
            $query->where('sale_receivables.status', (string) $filters['status']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('sale_receivables.customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['due_from'])) {
            $query->whereDate('sale_receivables.due_at', '>=', (string) $filters['due_from']);
        }

        if (! empty($filters['due_to'])) {
            $query->whereDate('sale_receivables.due_at', '<=', (string) $filters['due_to']);
        }

        if (! empty($filters['cursor'])) {
            $query->where('sale_receivables.id', '<', (int) $filters['cursor']);
        }

        return $query->get([
            'sale_receivables.id',
            'sale_receivables.total_amount',
            'sale_receivables.paid_amount',
            'sale_receivables.outstanding_amount',
            'sale_receivables.status',
            'sale_receivables.due_at',
            'customers.document_type',
            'customers.document_number',
            'customers.name as customer_name',
            'sales.reference as sale_reference',
        ])
            ->map(fn (object $receivable) => $this->formatSummary($receivable))
            ->all();
    }

    public function detail(int $tenantId, int $receivableId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $receivable = DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.id', $receivableId)
            ->first([
                'sale_receivables.id',
                'sale_receivables.total_amount',
                'sale_receivables.paid_amount',
                'sale_receivables.outstanding_amount',
                'sale_receivables.status',
                'sale_receivables.due_at',
                'customers.id as customer_id',
                'customers.document_type',
                'customers.document_number',
                'customers.name as customer_name',
                'sales.id as sale_id',
                'sales.reference as sale_reference',
            ]);

        if ($receivable === null) {
            throw new HttpException(404, 'Sale receivable not found.');
        }

        $payments = DB::table('sale_receivable_payments')
            ->where('sale_receivable_id', $receivableId)
            ->orderBy('id')
            ->get(['id', 'amount', 'payment_method', 'reference', 'paid_at'])
            ->map(fn (object $payment) => [
                'id' => (int) $payment->id,
                'amount' => round((float) $payment->amount, 2),
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
            ])
            ->all();

        $followUps = $this->followUps($tenantId, $receivableId);

        return [
            'id' => (int) $receivable->id,
            'total_amount' => round((float) $receivable->total_amount, 2),
            'paid_amount' => round((float) $receivable->paid_amount, 2),
            'outstanding_amount' => round((float) $receivable->outstanding_amount, 2),
            'status' => $receivable->status,
            'effective_status' => $this->effectiveStatus($receivable),
            'aging_bucket' => $this->agingBucket($receivable),
            'due_at' => $receivable->due_at,
            'customer' => [
                'id' => (int) $receivable->customer_id,
                'document_type' => $receivable->document_type,
                'document_number' => $receivable->document_number,
                'name' => $receivable->customer_name,
            ],
            'sale' => [
                'id' => (int) $receivable->sale_id,
                'reference' => $receivable->sale_reference,
            ],
            'payments' => $payments,
            'latest_follow_up' => $followUps[0] ?? null,
            'follow_ups' => $followUps,
        ];
    }

    public function followUps(int $tenantId, int $receivableId, ?int $cursor = null, int $limit = 100): array
    {
        $this->loadReceivable($tenantId, $receivableId);

        $query = DB::table('sale_receivable_follow_ups')
            ->join('users', 'users.id', '=', 'sale_receivable_follow_ups.user_id')
            ->where('sale_receivable_follow_ups.tenant_id', $tenantId)
            ->where('sale_receivable_follow_ups.sale_receivable_id', $receivableId)
            ->orderByDesc('sale_receivable_follow_ups.id')
            ->limit($this->limit($limit));

        if ($cursor !== null && $cursor > 0) {
            $query->where('sale_receivable_follow_ups.id', '<', $cursor);
        }

        return $query->get([
            'sale_receivable_follow_ups.id',
            'sale_receivable_follow_ups.type',
            'sale_receivable_follow_ups.note',
            'sale_receivable_follow_ups.promised_amount',
            'sale_receivable_follow_ups.outstanding_snapshot',
            'sale_receivable_follow_ups.promised_at',
            'sale_receivable_follow_ups.created_at',
            'users.id as user_id',
            'users.name as user_name',
        ])
            ->map(fn (object $followUp) => $this->formatFollowUp($followUp))
            ->all();
    }

    public function agingSummary(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $today = CarbonImmutable::now()->toDateString();
        $thirtyDaysAgo = CarbonImmutable::now()->subDays(30)->toDateString();
        $sixtyDaysAgo = CarbonImmutable::now()->subDays(60)->toDateString();

        $row = DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->selectRaw('
                SUM(CASE WHEN outstanding_amount > 0 AND (due_at IS NULL OR DATE(due_at) >= ?) THEN 1 ELSE 0 END) as current_count,
                COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND (due_at IS NULL OR DATE(due_at) >= ?) THEN outstanding_amount ELSE 0 END), 0) as current_amount,
                SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? AND DATE(due_at) >= ? THEN 1 ELSE 0 END) as overdue_1_30_count,
                COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? AND DATE(due_at) >= ? THEN outstanding_amount ELSE 0 END), 0) as overdue_1_30_amount,
                SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? AND DATE(due_at) >= ? THEN 1 ELSE 0 END) as overdue_31_60_count,
                COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? AND DATE(due_at) >= ? THEN outstanding_amount ELSE 0 END), 0) as overdue_31_60_amount,
                SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? THEN 1 ELSE 0 END) as overdue_61_plus_count,
                COALESCE(SUM(CASE WHEN outstanding_amount > 0 AND DATE(due_at) < ? THEN outstanding_amount ELSE 0 END), 0) as overdue_61_plus_amount,
                SUM(CASE WHEN outstanding_amount <= 0 THEN 1 ELSE 0 END) as paid_count,
                COALESCE(SUM(CASE WHEN outstanding_amount <= 0 THEN paid_amount ELSE 0 END), 0) as paid_amount
            ', [
                $today,
                $today,
                $today,
                $thirtyDaysAgo,
                $today,
                $thirtyDaysAgo,
                $thirtyDaysAgo,
                $sixtyDaysAgo,
                $thirtyDaysAgo,
                $sixtyDaysAgo,
                $sixtyDaysAgo,
                $sixtyDaysAgo,
            ])
            ->first();

        return [
            'tenant_id' => $tenantId,
            'summary' => [
                'current' => ['count' => (int) $row->current_count, 'amount' => round((float) $row->current_amount, 2)],
                'overdue_1_30' => ['count' => (int) $row->overdue_1_30_count, 'amount' => round((float) $row->overdue_1_30_amount, 2)],
                'overdue_31_60' => ['count' => (int) $row->overdue_31_60_count, 'amount' => round((float) $row->overdue_31_60_amount, 2)],
                'overdue_61_plus' => ['count' => (int) $row->overdue_61_plus_count, 'amount' => round((float) $row->overdue_61_plus_amount, 2)],
                'paid' => ['count' => (int) $row->paid_count, 'amount' => round((float) $row->paid_amount, 2)],
            ],
        ];
    }

    private function loadReceivable(int $tenantId, int $receivableId): object
    {
        $receivable = DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $receivableId)
            ->first(['id']);

        if ($receivable === null) {
            throw new HttpException(404, 'Sale receivable not found.');
        }

        return $receivable;
    }

    private function formatSummary(object $receivable): array
    {
        return [
            'id' => (int) $receivable->id,
            'total_amount' => round((float) $receivable->total_amount, 2),
            'paid_amount' => round((float) $receivable->paid_amount, 2),
            'outstanding_amount' => round((float) $receivable->outstanding_amount, 2),
            'status' => $receivable->status,
            'effective_status' => $this->effectiveStatus($receivable),
            'aging_bucket' => $this->agingBucket($receivable),
            'due_at' => $receivable->due_at,
            'customer' => [
                'document_type' => $receivable->document_type,
                'document_number' => $receivable->document_number,
                'name' => $receivable->customer_name,
            ],
            'sale_reference' => $receivable->sale_reference,
        ];
    }

    private function formatFollowUp(object $followUp): array
    {
        return [
            'id' => (int) $followUp->id,
            'type' => $followUp->type,
            'note' => $followUp->note,
            'promised_amount' => $followUp->promised_amount !== null ? round((float) $followUp->promised_amount, 2) : null,
            'outstanding_snapshot' => $followUp->outstanding_snapshot !== null ? round((float) $followUp->outstanding_snapshot, 2) : null,
            'promised_at' => $followUp->promised_at,
            'created_at' => $followUp->created_at,
            'user' => [
                'id' => (int) $followUp->user_id,
                'name' => $followUp->user_name,
            ],
        ];
    }

    private function effectiveStatus(object $receivable): string
    {
        if ((float) $receivable->outstanding_amount <= 0) {
            return 'paid';
        }

        if ($receivable->due_at !== null && CarbonImmutable::parse($receivable->due_at)->isPast()) {
            return 'overdue';
        }

        return $receivable->status;
    }

    private function agingBucket(object $receivable): string
    {
        if ((float) $receivable->outstanding_amount <= 0) {
            return 'paid';
        }

        if ($receivable->due_at === null) {
            return 'current';
        }

        $now = CarbonImmutable::now();
        $dueAt = CarbonImmutable::parse($receivable->due_at);

        if ($dueAt->isFuture() || $dueAt->isSameDay($now)) {
            return 'current';
        }

        $daysOverdue = abs($dueAt->diffInDays($now, false));

        if ($daysOverdue <= 30) {
            return 'overdue_1_30';
        }

        if ($daysOverdue <= 60) {
            return 'overdue_31_60';
        }

        return 'overdue_61_plus';
    }

    private function limit(mixed $limit): int
    {
        return max(1, min((int) $limit, 100));
    }
}
