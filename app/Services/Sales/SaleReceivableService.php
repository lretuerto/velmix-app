<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleReceivableService
{
    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('sale_receivables')
            ->join('customers', 'customers.id', '=', 'sale_receivables.customer_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->orderByDesc('sale_receivables.id')
            ->get([
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
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
            ])
            ->all();

        return [
            'id' => $receivable->id,
            'total_amount' => (float) $receivable->total_amount,
            'paid_amount' => (float) $receivable->paid_amount,
            'outstanding_amount' => (float) $receivable->outstanding_amount,
            'status' => $receivable->status,
            'effective_status' => $this->effectiveStatus($receivable),
            'aging_bucket' => $this->agingBucket($receivable),
            'due_at' => $receivable->due_at,
            'customer' => [
                'id' => $receivable->customer_id,
                'document_type' => $receivable->document_type,
                'document_number' => $receivable->document_number,
                'name' => $receivable->customer_name,
            ],
            'sale' => [
                'id' => $receivable->sale_id,
                'reference' => $receivable->sale_reference,
            ],
            'payments' => $payments,
        ];
    }

    public function pay(int $tenantId, int $userId, int $receivableId, float $amount, string $paymentMethod, string $reference): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($amount <= 0) {
            throw new HttpException(422, 'Payment amount must be valid.');
        }

        if (! in_array($paymentMethod, ['cash', 'card', 'transfer', 'bank_transfer'], true)) {
            throw new HttpException(422, 'Payment method is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $receivableId, $amount, $paymentMethod, $reference) {
            $receivable = DB::table('sale_receivables')
                ->where('tenant_id', $tenantId)
                ->where('id', $receivableId)
                ->lockForUpdate()
                ->first(['id', 'total_amount', 'paid_amount', 'outstanding_amount', 'status']);

            if ($receivable === null) {
                throw new HttpException(404, 'Sale receivable not found.');
            }

            if ($receivable->status === 'paid') {
                throw new HttpException(422, 'Sale receivable is already fully paid.');
            }

            if ($amount > (float) $receivable->outstanding_amount) {
                throw new HttpException(422, 'Payment amount exceeds outstanding amount.');
            }

            $paymentId = DB::table('sale_receivable_payments')->insertGetId([
                'sale_receivable_id' => $receivable->id,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newPaidAmount = round((float) $receivable->paid_amount + $amount, 2);
            $newOutstandingAmount = round((float) $receivable->total_amount - $newPaidAmount, 2);
            $newStatus = $newOutstandingAmount <= 0 ? 'paid' : ($newPaidAmount > 0 ? 'partial_paid' : 'pending');

            DB::table('sale_receivables')
                ->where('id', $receivable->id)
                ->update([
                    'paid_amount' => $newPaidAmount,
                    'outstanding_amount' => $newOutstandingAmount,
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            return [
                'payment_id' => $paymentId,
                'sale_receivable_id' => $receivable->id,
                'amount' => round($amount, 2),
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'paid_amount' => $newPaidAmount,
                'outstanding_amount' => $newOutstandingAmount,
                'status' => $newStatus,
            ];
        });
    }

    public function agingSummary(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $receivables = DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'paid_amount', 'outstanding_amount', 'status', 'due_at']);

        $summary = [
            'current' => ['count' => 0, 'amount' => 0.0],
            'overdue_1_30' => ['count' => 0, 'amount' => 0.0],
            'overdue_31_60' => ['count' => 0, 'amount' => 0.0],
            'overdue_61_plus' => ['count' => 0, 'amount' => 0.0],
            'paid' => ['count' => 0, 'amount' => 0.0],
        ];

        foreach ($receivables as $receivable) {
            $bucket = $this->agingBucket($receivable);

            if ($bucket === 'paid') {
                $summary['paid']['count']++;
                $summary['paid']['amount'] = round($summary['paid']['amount'] + (float) $receivable->paid_amount, 2);
                continue;
            }

            $summary[$bucket]['count']++;
            $summary[$bucket]['amount'] = round($summary[$bucket]['amount'] + (float) $receivable->outstanding_amount, 2);
        }

        return [
            'tenant_id' => $tenantId,
            'summary' => $summary,
        ];
    }

    private function formatSummary(object $receivable): array
    {
        return [
            'id' => $receivable->id,
            'total_amount' => (float) $receivable->total_amount,
            'paid_amount' => (float) $receivable->paid_amount,
            'outstanding_amount' => (float) $receivable->outstanding_amount,
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
}
