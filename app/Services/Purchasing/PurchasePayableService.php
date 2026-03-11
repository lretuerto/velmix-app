<?php

namespace App\Services\Purchasing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchasePayableService
{
    public function applyCredits(int $tenantId, int $userId, int $payableId, ?float $amount = null): array
    {
        return DB::transaction(function () use ($tenantId, $userId, $payableId, $amount) {
            $result = app(SupplierCreditService::class)->applyAvailableCredits(
                $tenantId,
                $userId,
                $payableId,
                $amount,
                'manual'
            );

            return $result;
        });
    }

    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->orderByDesc('purchase_payables.id')
            ->get([
                'purchase_payables.id',
                'purchase_payables.total_amount',
                'purchase_payables.paid_amount',
                'purchase_payables.outstanding_amount',
                'purchase_payables.status',
                'purchase_payables.due_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
                'purchase_receipts.reference as receipt_reference',
            ])
            ->map(function (object $payable) {
                $payable->supplier_credit_applied_amount = (float) DB::table('supplier_credit_applications')
                    ->where('purchase_payable_id', $payable->id)
                    ->sum('amount');

                return $this->formatPayableSummary($payable);
            })
            ->all();
    }

    public function detail(int $tenantId, int $payableId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $payable = DB::table('purchase_payables')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_payables.supplier_id')
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_payables.purchase_receipt_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.id', $payableId)
            ->first([
                'purchase_payables.id',
                'purchase_payables.total_amount',
                'purchase_payables.paid_amount',
                'purchase_payables.outstanding_amount',
                'purchase_payables.status',
                'purchase_payables.due_at',
                'suppliers.tax_id as supplier_tax_id',
                'suppliers.name as supplier_name',
                'purchase_receipts.id as receipt_id',
                'purchase_receipts.reference as receipt_reference',
            ]);

        if ($payable === null) {
            throw new HttpException(404, 'Purchase payable not found.');
        }

        $payments = DB::table('purchase_payments')
            ->where('purchase_payable_id', $payableId)
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

        $creditApplications = DB::table('supplier_credit_applications')
            ->join('supplier_credits', 'supplier_credits.id', '=', 'supplier_credit_applications.supplier_credit_id')
            ->where('supplier_credit_applications.purchase_payable_id', $payableId)
            ->orderBy('supplier_credit_applications.id')
            ->get([
                'supplier_credit_applications.id',
                'supplier_credit_applications.amount',
                'supplier_credit_applications.application_type',
                'supplier_credit_applications.applied_at',
                'supplier_credits.reference as supplier_credit_reference',
            ])
            ->map(fn (object $application) => [
                'id' => $application->id,
                'amount' => (float) $application->amount,
                'application_type' => $application->application_type,
                'applied_at' => $application->applied_at,
                'supplier_credit_reference' => $application->supplier_credit_reference,
            ])
            ->all();

        return [
            'id' => $payable->id,
            'total_amount' => (float) $payable->total_amount,
            'paid_amount' => (float) $payable->paid_amount,
            'outstanding_amount' => (float) $payable->outstanding_amount,
            'supplier_credit_applied_amount' => round(array_sum(array_column($creditApplications, 'amount')), 2),
            'status' => $payable->status,
            'effective_status' => $this->effectiveStatus($payable),
            'aging_bucket' => $this->agingBucket($payable),
            'due_at' => $payable->due_at,
            'supplier' => [
                'tax_id' => $payable->supplier_tax_id,
                'name' => $payable->supplier_name,
            ],
            'purchase_receipt' => [
                'id' => $payable->receipt_id,
                'reference' => $payable->receipt_reference,
            ],
            'payments' => $payments,
            'supplier_credit_applications' => $creditApplications,
        ];
    }

    public function pay(int $tenantId, int $userId, int $payableId, float $amount, string $paymentMethod, string $reference): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($amount <= 0) {
            throw new HttpException(422, 'Payment amount must be valid.');
        }

        if (! in_array($paymentMethod, ['cash', 'bank_transfer', 'card'], true)) {
            throw new HttpException(422, 'Payment method is invalid.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $payableId, $amount, $paymentMethod, $reference) {
            $payable = DB::table('purchase_payables')
                ->where('tenant_id', $tenantId)
                ->where('id', $payableId)
                ->lockForUpdate()
                ->first(['id', 'total_amount', 'paid_amount', 'outstanding_amount', 'status']);

            if ($payable === null) {
                throw new HttpException(404, 'Purchase payable not found.');
            }

            if ($payable->status === 'paid') {
                throw new HttpException(422, 'Purchase payable is already fully paid.');
            }

            $outstandingAmount = round((float) $payable->outstanding_amount, 2);

            if ($amount > $outstandingAmount) {
                throw new HttpException(422, 'Payment amount exceeds outstanding amount.');
            }

            $paymentId = DB::table('purchase_payments')->insertGetId([
                'purchase_payable_id' => $payable->id,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newPaidAmount = round((float) $payable->paid_amount + $amount, 2);
            $newOutstandingAmount = round((float) $payable->total_amount - $newPaidAmount, 2);
            $newStatus = $newOutstandingAmount <= 0 ? 'paid' : ($newPaidAmount > 0 ? 'partial_paid' : 'pending');

            DB::table('purchase_payables')
                ->where('id', $payable->id)
                ->update([
                    'paid_amount' => $newPaidAmount,
                    'outstanding_amount' => $newOutstandingAmount,
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            return [
                'payment_id' => $paymentId,
                'purchase_payable_id' => $payable->id,
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

        $payables = DB::table('purchase_payables')
            ->where('tenant_id', $tenantId)
            ->get(['id', 'total_amount', 'paid_amount', 'outstanding_amount', 'status', 'due_at']);

        $buckets = [
            'current' => ['count' => 0, 'amount' => 0.0],
            'overdue_1_30' => ['count' => 0, 'amount' => 0.0],
            'overdue_31_60' => ['count' => 0, 'amount' => 0.0],
            'overdue_61_plus' => ['count' => 0, 'amount' => 0.0],
            'paid' => ['count' => 0, 'amount' => 0.0],
        ];

        foreach ($payables as $payable) {
            $bucket = $this->agingBucket($payable);
            $amount = round((float) $payable->outstanding_amount, 2);

            if ($bucket === 'paid') {
                $buckets['paid']['count']++;
                $buckets['paid']['amount'] = round($buckets['paid']['amount'] + (float) $payable->paid_amount, 2);
                continue;
            }

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] = round($buckets[$bucket]['amount'] + $amount, 2);
        }

        return [
            'tenant_id' => $tenantId,
            'summary' => $buckets,
        ];
    }

    private function formatPayableSummary(object $payable): array
    {
        return [
            'id' => $payable->id,
            'total_amount' => (float) $payable->total_amount,
            'paid_amount' => (float) $payable->paid_amount,
            'outstanding_amount' => (float) $payable->outstanding_amount,
            'supplier_credit_applied_amount' => (float) ($payable->supplier_credit_applied_amount ?? 0),
            'status' => $payable->status,
            'effective_status' => $this->effectiveStatus($payable),
            'aging_bucket' => $this->agingBucket($payable),
            'due_at' => $payable->due_at,
            'supplier' => [
                'tax_id' => $payable->supplier_tax_id,
                'name' => $payable->supplier_name,
            ],
            'purchase_receipt_reference' => $payable->receipt_reference,
        ];
    }

    private function effectiveStatus(object $payable): string
    {
        if ($payable->status === 'adjusted') {
            return 'adjusted';
        }

        if ((float) $payable->outstanding_amount <= 0) {
            return 'paid';
        }

        if ($payable->due_at !== null && CarbonImmutable::parse($payable->due_at)->isPast()) {
            return 'overdue';
        }

        return $payable->status;
    }

    private function agingBucket(object $payable): string
    {
        if ((float) $payable->outstanding_amount <= 0) {
            return 'paid';
        }

        if ($payable->due_at === null) {
            return 'current';
        }

        $now = CarbonImmutable::now();
        $dueAt = CarbonImmutable::parse($payable->due_at);

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
