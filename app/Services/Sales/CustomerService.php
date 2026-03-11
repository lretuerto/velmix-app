<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerService
{
    public function list(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get([
                'id',
                'document_type',
                'document_number',
                'name',
                'phone',
                'email',
                'status',
            ])
            ->map(fn (object $customer) => [
                'id' => $customer->id,
                'document_type' => $customer->document_type,
                'document_number' => $customer->document_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'status' => $customer->status,
            ])
            ->all();
    }

    public function create(
        int $tenantId,
        string $documentType,
        string $documentNumber,
        string $name,
        ?string $phone = null,
        ?string $email = null
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (trim($documentType) === '' || trim($documentNumber) === '' || trim($name) === '') {
            throw new HttpException(422, 'Customer data is required.');
        }

        $exists = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('document_number', $documentNumber)
            ->exists();

        if ($exists) {
            throw new HttpException(422, 'Customer document already exists in tenant.');
        }

        $customerId = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $customerId,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'status' => 'active',
        ];
    }

    public function statement(int $tenantId, int $customerId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $customer = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->first(['id', 'document_type', 'document_number', 'name', 'phone', 'email', 'status']);

        if ($customer === null) {
            throw new HttpException(404, 'Customer not found.');
        }

        $sales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->get(['id', 'reference', 'status', 'payment_method', 'total_amount', 'created_at'])
            ->map(fn (object $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'total_amount' => (float) $sale->total_amount,
                'created_at' => $sale->created_at,
            ])
            ->all();

        $receivables = DB::table('sale_receivables')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->orderByDesc('sale_receivables.id')
            ->get([
                'sale_receivables.id',
                'sale_receivables.total_amount',
                'sale_receivables.paid_amount',
                'sale_receivables.outstanding_amount',
                'sale_receivables.status',
                'sale_receivables.due_at',
                'sales.reference as sale_reference',
            ])
            ->map(fn (object $receivable) => [
                'id' => $receivable->id,
                'total_amount' => (float) $receivable->total_amount,
                'paid_amount' => (float) $receivable->paid_amount,
                'outstanding_amount' => (float) $receivable->outstanding_amount,
                'status' => $receivable->status,
                'due_at' => $receivable->due_at,
                'sale_reference' => $receivable->sale_reference,
            ])
            ->all();

        $payments = DB::table('sale_receivable_payments')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_payments.sale_receivable_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->orderByDesc('sale_receivable_payments.id')
            ->get([
                'sale_receivable_payments.id',
                'sale_receivable_payments.amount',
                'sale_receivable_payments.payment_method',
                'sale_receivable_payments.reference',
                'sale_receivable_payments.paid_at',
                'sales.reference as sale_reference',
            ])
            ->map(fn (object $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
                'sale_reference' => $payment->sale_reference,
            ])
            ->all();

        return [
            'customer' => [
                'id' => $customer->id,
                'document_type' => $customer->document_type,
                'document_number' => $customer->document_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'status' => $customer->status,
            ],
            'summary' => [
                'sales_total' => round(collect($sales)->sum('total_amount'), 2),
                'receivables_total' => round(collect($receivables)->sum('total_amount'), 2),
                'payments_total' => round(collect($payments)->sum('amount'), 2),
                'outstanding_total' => round(collect($receivables)->sum('outstanding_amount'), 2),
            ],
            'sales' => $sales,
            'receivables' => $receivables,
            'payments' => $payments,
        ];
    }
}
