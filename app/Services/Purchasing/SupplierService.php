<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierService
{
    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'tax_id', 'name', 'status'])
            ->map(fn (object $supplier) => [
                'id' => $supplier->id,
                'tenant_id' => $supplier->tenant_id,
                'tax_id' => $supplier->tax_id,
                'name' => $supplier->name,
                'status' => $supplier->status,
            ])
            ->all();
    }

    public function create(int $tenantId, string $taxId, string $name): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $exists = DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->where('tax_id', $taxId)
            ->exists();

        if ($exists) {
            throw new HttpException(422, 'Supplier tax ID already exists for tenant.');
        }

        $supplierId = DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $supplierId,
            'tenant_id' => $tenantId,
            'tax_id' => $taxId,
            'name' => $name,
            'status' => 'active',
        ];
    }

    public function statement(int $tenantId, int $supplierId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $supplier = DB::table('suppliers')
            ->where('tenant_id', $tenantId)
            ->where('id', $supplierId)
            ->first(['id', 'tax_id', 'name', 'status']);

        if ($supplier === null) {
            throw new HttpException(404, 'Supplier not found.');
        }

        $receipts = DB::table('purchase_receipts')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->get(['id', 'reference', 'total_amount', 'received_at'])
            ->map(fn (object $receipt) => [
                'id' => $receipt->id,
                'reference' => $receipt->reference,
                'total_amount' => (float) $receipt->total_amount,
                'received_at' => $receipt->received_at,
            ])
            ->all();

        $payables = DB::table('purchase_payables')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->get(['id', 'purchase_receipt_id', 'total_amount', 'paid_amount', 'outstanding_amount', 'status', 'due_at'])
            ->map(fn (object $payable) => [
                'id' => $payable->id,
                'purchase_receipt_id' => $payable->purchase_receipt_id,
                'total_amount' => (float) $payable->total_amount,
                'paid_amount' => (float) $payable->paid_amount,
                'outstanding_amount' => (float) $payable->outstanding_amount,
                'status' => $payable->status,
                'due_at' => $payable->due_at,
            ])
            ->all();

        $payments = DB::table('purchase_payments')
            ->join('purchase_payables', 'purchase_payables.id', '=', 'purchase_payments.purchase_payable_id')
            ->where('purchase_payables.tenant_id', $tenantId)
            ->where('purchase_payables.supplier_id', $supplierId)
            ->orderByDesc('purchase_payments.id')
            ->get([
                'purchase_payments.id',
                'purchase_payments.purchase_payable_id',
                'purchase_payments.amount',
                'purchase_payments.payment_method',
                'purchase_payments.reference',
                'purchase_payments.paid_at',
            ])
            ->map(fn (object $payment) => [
                'id' => $payment->id,
                'purchase_payable_id' => $payment->purchase_payable_id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
            ])
            ->all();

        $returns = DB::table('purchase_returns')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->get(['id', 'purchase_receipt_id', 'reference', 'reason', 'total_amount', 'returned_at'])
            ->map(fn (object $return) => [
                'id' => $return->id,
                'purchase_receipt_id' => $return->purchase_receipt_id,
                'reference' => $return->reference,
                'reason' => $return->reason,
                'total_amount' => (float) $return->total_amount,
                'returned_at' => $return->returned_at,
            ])
            ->all();

        $supplierCredits = DB::table('supplier_credits')
            ->where('tenant_id', $tenantId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('id')
            ->get(['id', 'purchase_payable_id', 'purchase_return_id', 'amount', 'remaining_amount', 'status', 'reference'])
            ->map(fn (object $credit) => [
                'id' => $credit->id,
                'purchase_payable_id' => $credit->purchase_payable_id,
                'purchase_return_id' => $credit->purchase_return_id,
                'amount' => (float) $credit->amount,
                'remaining_amount' => (float) $credit->remaining_amount,
                'status' => $credit->status,
                'reference' => $credit->reference,
            ])
            ->all();

        return [
            'supplier' => [
                'id' => $supplier->id,
                'tax_id' => $supplier->tax_id,
                'name' => $supplier->name,
                'status' => $supplier->status,
            ],
            'summary' => [
                'receipts_total' => round(array_sum(array_column($receipts, 'total_amount')), 2),
                'payables_total' => round(array_sum(array_column($payables, 'total_amount')), 2),
                'payments_total' => round(array_sum(array_column($payments, 'amount')), 2),
                'returns_total' => round(array_sum(array_column($returns, 'total_amount')), 2),
                'supplier_credits_total' => round(array_sum(array_column($supplierCredits, 'remaining_amount')), 2),
                'outstanding_total' => round(array_sum(array_column($payables, 'outstanding_amount')), 2),
            ],
            'receipts' => $receipts,
            'payables' => $payables,
            'payments' => $payments,
            'returns' => $returns,
            'supplier_credits' => $supplierCredits,
        ];
    }
}
