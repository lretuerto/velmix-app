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

        $receivableSummary = $this->customerReceivableSummary($tenantId);

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
                'credit_limit',
                'credit_days',
                'block_on_overdue',
                'status',
            ])
            ->map(fn (object $customer) => [
                'id' => $customer->id,
                'document_type' => $customer->document_type,
                'document_number' => $customer->document_number,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
                'credit_days' => $customer->credit_days !== null ? (int) $customer->credit_days : null,
                'block_on_overdue' => (bool) $customer->block_on_overdue,
                'outstanding_total' => $receivableSummary[$customer->id]['outstanding_total'] ?? 0.0,
                'overdue_total' => $receivableSummary[$customer->id]['overdue_total'] ?? 0.0,
                'available_credit' => $customer->credit_limit !== null
                    ? round((float) $customer->credit_limit - ($receivableSummary[$customer->id]['outstanding_total'] ?? 0), 2)
                    : null,
                'credit_utilization_pct' => $customer->credit_limit !== null && (float) $customer->credit_limit > 0
                    ? round((($receivableSummary[$customer->id]['outstanding_total'] ?? 0) / (float) $customer->credit_limit) * 100, 2)
                    : null,
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
        ?string $email = null,
        ?float $creditLimit = null,
        ?int $creditDays = null,
        bool $blockOnOverdue = true
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (trim($documentType) === '' || trim($documentNumber) === '' || trim($name) === '') {
            throw new HttpException(422, 'Customer data is required.');
        }

        if ($creditLimit !== null && $creditLimit < 0) {
            throw new HttpException(422, 'Customer credit_limit must be valid.');
        }

        if ($creditDays !== null && $creditDays < 0) {
            throw new HttpException(422, 'Customer credit_days must be valid.');
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
            'credit_limit' => $creditLimit,
            'credit_days' => $creditDays,
            'block_on_overdue' => $blockOnOverdue,
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
            'credit_limit' => $creditLimit,
            'credit_days' => $creditDays,
            'block_on_overdue' => $blockOnOverdue,
            'status' => 'active',
        ];
    }

    public function update(int $tenantId, int $customerId, array $attributes): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $customer = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->first([
                'id',
                'document_type',
                'document_number',
                'name',
                'phone',
                'email',
                'credit_limit',
                'credit_days',
                'block_on_overdue',
                'status',
            ]);

        if ($customer === null) {
            throw new HttpException(404, 'Customer not found.');
        }

        if ($attributes === []) {
            throw new HttpException(422, 'At least one customer attribute is required.');
        }

        $payload = [];

        if (array_key_exists('document_type', $attributes)) {
            $payload['document_type'] = $attributes['document_type'];
        }

        if (array_key_exists('document_number', $attributes)) {
            $payload['document_number'] = $attributes['document_number'];
        }

        if (
            array_key_exists('document_type', $payload)
            || array_key_exists('document_number', $payload)
        ) {
            $documentType = $payload['document_type'] ?? $customer->document_type;
            $documentNumber = $payload['document_number'] ?? $customer->document_number;

            $exists = DB::table('customers')
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('document_number', $documentNumber)
                ->where('id', '!=', $customerId)
                ->exists();

            if ($exists) {
                throw new HttpException(422, 'Customer document already exists in tenant.');
            }
        }

        foreach (['name', 'phone', 'email', 'status'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $payload[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('credit_limit', $attributes)) {
            $creditLimit = $attributes['credit_limit'];

            if ($creditLimit !== null && $creditLimit < 0) {
                throw new HttpException(422, 'Customer credit_limit must be valid.');
            }

            $payload['credit_limit'] = $creditLimit;
        }

        if (array_key_exists('credit_days', $attributes)) {
            $creditDays = $attributes['credit_days'];

            if ($creditDays !== null && $creditDays < 0) {
                throw new HttpException(422, 'Customer credit_days must be valid.');
            }

            $payload['credit_days'] = $creditDays;
        }

        if (array_key_exists('block_on_overdue', $attributes)) {
            $payload['block_on_overdue'] = (bool) $attributes['block_on_overdue'];
        }

        $payload['updated_at'] = now();

        DB::table('customers')
            ->where('id', $customerId)
            ->update($payload);

        $updated = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->first([
                'id',
                'document_type',
                'document_number',
                'name',
                'phone',
                'email',
                'credit_limit',
                'credit_days',
                'block_on_overdue',
                'status',
            ]);

        return [
            'id' => $updated->id,
            'document_type' => $updated->document_type,
            'document_number' => $updated->document_number,
            'name' => $updated->name,
            'phone' => $updated->phone,
            'email' => $updated->email,
            'credit_limit' => $updated->credit_limit !== null ? (float) $updated->credit_limit : null,
            'credit_days' => $updated->credit_days !== null ? (int) $updated->credit_days : null,
            'block_on_overdue' => (bool) $updated->block_on_overdue,
            'status' => $updated->status,
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
            ->first([
                'id',
                'document_type',
                'document_number',
                'name',
                'phone',
                'email',
                'credit_limit',
                'credit_days',
                'block_on_overdue',
                'status',
            ]);

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
                'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
                'credit_days' => $customer->credit_days !== null ? (int) $customer->credit_days : null,
                'block_on_overdue' => (bool) $customer->block_on_overdue,
                'status' => $customer->status,
            ],
            'summary' => [
                'sales_total' => round(collect($sales)->sum('total_amount'), 2),
                'receivables_total' => round(collect($receivables)->sum('total_amount'), 2),
                'payments_total' => round(collect($payments)->sum('amount'), 2),
                'outstanding_total' => round(collect($receivables)->sum('outstanding_amount'), 2),
                'available_credit' => $customer->credit_limit !== null
                    ? round((float) $customer->credit_limit - collect($receivables)->sum('outstanding_amount'), 2)
                    : null,
                'credit_utilization_pct' => $customer->credit_limit !== null && (float) $customer->credit_limit > 0
                    ? round((collect($receivables)->sum('outstanding_amount') / (float) $customer->credit_limit) * 100, 2)
                    : null,
                'overdue_receivable_count' => collect($receivables)->filter(function (array $receivable) {
                    return (float) $receivable['outstanding_amount'] > 0
                        && $receivable['due_at'] !== null
                        && $receivable['due_at'] < now();
                })->count(),
            ],
            'sales' => $sales,
            'receivables' => $receivables,
            'payments' => $payments,
        ];
    }

    private function customerReceivableSummary(int $tenantId): array
    {
        return DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->where('outstanding_amount', '>', 0)
            ->selectRaw("
                customer_id,
                COALESCE(SUM(outstanding_amount), 0) as outstanding_total,
                COALESCE(SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? THEN outstanding_amount ELSE 0 END), 0) as overdue_total
            ", [now()])
            ->groupBy('customer_id')
            ->get()
            ->mapWithKeys(fn (object $row) => [
                $row->customer_id => [
                    'outstanding_total' => round((float) $row->outstanding_total, 2),
                    'overdue_total' => round((float) $row->overdue_total, 2),
                ],
            ])
            ->all();
    }
}
