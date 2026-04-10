<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierCreditService
{
    public function applyAvailableCredits(
        int $tenantId,
        int $userId,
        int $payableId,
        ?float $requestedAmount = null,
        string $applicationType = 'manual'
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if ($requestedAmount !== null && $requestedAmount <= 0) {
            throw new HttpException(422, 'Requested credit amount must be valid.');
        }

        $payable = DB::table('purchase_payables')
            ->where('tenant_id', $tenantId)
            ->where('id', $payableId)
            ->lockForUpdate()
            ->first([
                'id',
                'supplier_id',
                'total_amount',
                'paid_amount',
                'outstanding_amount',
                'status',
            ]);

        if ($payable === null) {
            throw new HttpException(404, 'Purchase payable not found.');
        }

        if ((float) $payable->outstanding_amount <= 0) {
            throw new HttpException(422, 'Purchase payable has no outstanding amount.');
        }

        $availableCredits = DB::table('supplier_credits')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $payable->supplier_id)
            ->where('remaining_amount', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get([
                'id',
                'amount',
                'remaining_amount',
                'status',
                'reference',
            ]);

        if ($availableCredits->isEmpty()) {
            throw new HttpException(422, 'Supplier has no available credits.');
        }

        $availableTotal = round((float) $availableCredits->sum('remaining_amount'), 2);
        $outstandingAmount = round((float) $payable->outstanding_amount, 2);

        if ($requestedAmount !== null) {
            $requestedAmount = round($requestedAmount, 2);

            if ($requestedAmount > $outstandingAmount) {
                throw new HttpException(422, 'Requested credit amount exceeds outstanding amount.');
            }

            if ($requestedAmount > $availableTotal) {
                throw new HttpException(422, 'Requested credit amount exceeds available supplier credits.');
            }
        }

        $remainingToApply = $requestedAmount ?? min($availableTotal, $outstandingAmount);
        $applications = [];
        $appliedAmount = 0.0;

        foreach ($availableCredits as $credit) {
            if ($remainingToApply <= 0) {
                break;
            }

            $creditRemaining = round((float) $credit->remaining_amount, 2);
            $amountToApply = min($creditRemaining, $remainingToApply);

            if ($amountToApply <= 0) {
                continue;
            }

            DB::table('supplier_credit_applications')->insert([
                'tenant_id' => $tenantId,
                'supplier_credit_id' => $credit->id,
                'purchase_payable_id' => $payable->id,
                'user_id' => $userId,
                'amount' => $amountToApply,
                'application_type' => $applicationType,
                'applied_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newRemainingAmount = round($creditRemaining - $amountToApply, 2);
            $newStatus = $newRemainingAmount <= 0 ? 'applied' : 'partially_applied';

            DB::table('supplier_credits')
                ->where('id', $credit->id)
                ->update([
                    'remaining_amount' => $newRemainingAmount,
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            $remainingToApply = round($remainingToApply - $amountToApply, 2);
            $appliedAmount = round($appliedAmount + $amountToApply, 2);

            $applications[] = [
                'supplier_credit_id' => $credit->id,
                'supplier_credit_reference' => $credit->reference,
                'amount' => $amountToApply,
                'remaining_amount' => $newRemainingAmount,
                'status' => $newStatus,
            ];
        }

        if ($appliedAmount <= 0) {
            throw new HttpException(422, 'No supplier credit could be applied.');
        }

        $newPaidAmount = round((float) $payable->paid_amount + $appliedAmount, 2);
        $newOutstandingAmount = round((float) $payable->outstanding_amount - $appliedAmount, 2);
        $newStatus = $newOutstandingAmount <= 0 ? 'paid' : 'partial_paid';

        DB::table('purchase_payables')
            ->where('id', $payable->id)
            ->update([
                'paid_amount' => $newPaidAmount,
                'outstanding_amount' => $newOutstandingAmount,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        return [
            'purchase_payable_id' => $payable->id,
            'applied_amount' => $appliedAmount,
            'paid_amount' => $newPaidAmount,
            'outstanding_amount' => $newOutstandingAmount,
            'status' => $newStatus,
            'applications' => $applications,
        ];
    }
}
