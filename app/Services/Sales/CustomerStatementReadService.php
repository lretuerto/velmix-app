<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerStatementReadService
{
    public function statement(int $tenantId, int $customerId): array
    {
        $customer = $this->customer($tenantId, $customerId);

        return [
            'customer' => $this->formatCustomer($customer),
            'summary' => $this->summary($tenantId, $customerId, $customer)['summary'],
            'sales' => $this->sales($tenantId, $customerId),
            'receivables' => $this->receivables($tenantId, $customerId),
            'payments' => $this->payments($tenantId, $customerId),
            'follow_ups' => $this->followUps($tenantId, $customerId),
        ];
    }

    public function summary(int $tenantId, int $customerId, ?object $customer = null): array
    {
        $customer ??= $this->customer($tenantId, $customerId);

        $sales = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as sales_total')
            ->first();

        $receivables = DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->selectRaw('
                COUNT(*) as receivable_count,
                COALESCE(SUM(total_amount), 0) as receivables_total,
                COALESCE(SUM(outstanding_amount), 0) as outstanding_total,
                SUM(CASE WHEN outstanding_amount > 0 AND due_at IS NOT NULL AND DATE(due_at) < ? THEN 1 ELSE 0 END) as overdue_receivable_count
            ', [now()->toDateString()])
            ->first();

        $payments = DB::table('sale_receivable_payments')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_payments.sale_receivable_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->selectRaw('COUNT(*) as payment_count, COALESCE(SUM(sale_receivable_payments.amount), 0) as payments_total')
            ->first();

        $followUps = DB::table('sale_receivable_follow_ups')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_follow_ups.sale_receivable_id')
            ->where('sale_receivable_follow_ups.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->selectRaw("
                COUNT(*) as follow_up_count,
                SUM(CASE WHEN sale_receivable_follow_ups.type = 'promise' THEN 1 ELSE 0 END) as promised_follow_up_count
            ")
            ->first();

        $outstandingTotal = round((float) ($receivables->outstanding_total ?? 0), 2);
        $creditLimit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;

        return [
            'customer' => $this->formatCustomer($customer),
            'summary' => [
                'sales_count' => (int) ($sales->sales_count ?? 0),
                'sales_total' => round((float) ($sales->sales_total ?? 0), 2),
                'receivable_count' => (int) ($receivables->receivable_count ?? 0),
                'receivables_total' => round((float) ($receivables->receivables_total ?? 0), 2),
                'payments_count' => (int) ($payments->payment_count ?? 0),
                'payments_total' => round((float) ($payments->payments_total ?? 0), 2),
                'outstanding_total' => $outstandingTotal,
                'available_credit' => $creditLimit !== null ? round($creditLimit - $outstandingTotal, 2) : null,
                'credit_utilization_pct' => $creditLimit !== null && $creditLimit > 0
                    ? round(($outstandingTotal / $creditLimit) * 100, 2)
                    : null,
                'overdue_receivable_count' => (int) ($receivables->overdue_receivable_count ?? 0),
                'follow_up_count' => (int) ($followUps->follow_up_count ?? 0),
                'promised_follow_up_count' => (int) ($followUps->promised_follow_up_count ?? 0),
            ],
        ];
    }

    public function sales(int $tenantId, int $customerId, ?int $cursor = null, int $limit = 100): array
    {
        $this->customer($tenantId, $customerId);

        $query = DB::table('sales')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->limit($this->limit($limit));

        if ($cursor !== null && $cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        return $query->get(['id', 'reference', 'status', 'payment_method', 'total_amount', 'created_at'])
            ->map(fn (object $sale) => [
                'id' => (int) $sale->id,
                'reference' => $sale->reference,
                'status' => $sale->status,
                'payment_method' => $sale->payment_method,
                'total_amount' => round((float) $sale->total_amount, 2),
                'created_at' => $sale->created_at,
            ])
            ->all();
    }

    public function receivables(int $tenantId, int $customerId, ?int $cursor = null, int $limit = 100): array
    {
        $this->customer($tenantId, $customerId);

        $query = DB::table('sale_receivables')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->orderByDesc('sale_receivables.id')
            ->limit($this->limit($limit));

        if ($cursor !== null && $cursor > 0) {
            $query->where('sale_receivables.id', '<', $cursor);
        }

        return $query->get([
            'sale_receivables.id',
            'sale_receivables.total_amount',
            'sale_receivables.paid_amount',
            'sale_receivables.outstanding_amount',
            'sale_receivables.status',
            'sale_receivables.due_at',
            'sales.reference as sale_reference',
        ])
            ->map(fn (object $receivable) => [
                'id' => (int) $receivable->id,
                'total_amount' => round((float) $receivable->total_amount, 2),
                'paid_amount' => round((float) $receivable->paid_amount, 2),
                'outstanding_amount' => round((float) $receivable->outstanding_amount, 2),
                'status' => $receivable->status,
                'due_at' => $receivable->due_at,
                'sale_reference' => $receivable->sale_reference,
            ])
            ->all();
    }

    public function payments(int $tenantId, int $customerId, ?int $cursor = null, int $limit = 100): array
    {
        $this->customer($tenantId, $customerId);

        $query = DB::table('sale_receivable_payments')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_payments.sale_receivable_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->where('sale_receivables.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->orderByDesc('sale_receivable_payments.id')
            ->limit($this->limit($limit));

        if ($cursor !== null && $cursor > 0) {
            $query->where('sale_receivable_payments.id', '<', $cursor);
        }

        return $query->get([
            'sale_receivable_payments.id',
            'sale_receivable_payments.amount',
            'sale_receivable_payments.payment_method',
            'sale_receivable_payments.reference',
            'sale_receivable_payments.paid_at',
            'sales.reference as sale_reference',
        ])
            ->map(fn (object $payment) => [
                'id' => (int) $payment->id,
                'amount' => round((float) $payment->amount, 2),
                'payment_method' => $payment->payment_method,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
                'sale_reference' => $payment->sale_reference,
            ])
            ->all();
    }

    public function followUps(int $tenantId, int $customerId, ?int $cursor = null, int $limit = 100): array
    {
        $this->customer($tenantId, $customerId);

        $query = DB::table('sale_receivable_follow_ups')
            ->join('sale_receivables', 'sale_receivables.id', '=', 'sale_receivable_follow_ups.sale_receivable_id')
            ->join('sales', 'sales.id', '=', 'sale_receivables.sale_id')
            ->join('users', 'users.id', '=', 'sale_receivable_follow_ups.user_id')
            ->where('sale_receivable_follow_ups.tenant_id', $tenantId)
            ->where('sale_receivables.customer_id', $customerId)
            ->orderByDesc('sale_receivable_follow_ups.id')
            ->limit($this->limit($limit));

        if ($cursor !== null && $cursor > 0) {
            $query->where('sale_receivable_follow_ups.id', '<', $cursor);
        }

        return $query->get([
            'sale_receivable_follow_ups.id',
            'sale_receivable_follow_ups.sale_receivable_id',
            'sale_receivable_follow_ups.type',
            'sale_receivable_follow_ups.note',
            'sale_receivable_follow_ups.promised_amount',
            'sale_receivable_follow_ups.promised_at',
            'sale_receivable_follow_ups.created_at',
            'sales.reference as sale_reference',
            'users.id as user_id',
            'users.name as user_name',
        ])
            ->map(fn (object $followUp) => [
                'id' => (int) $followUp->id,
                'sale_receivable_id' => (int) $followUp->sale_receivable_id,
                'type' => $followUp->type,
                'note' => $followUp->note,
                'promised_amount' => $followUp->promised_amount !== null ? round((float) $followUp->promised_amount, 2) : null,
                'promised_at' => $followUp->promised_at,
                'created_at' => $followUp->created_at,
                'sale_reference' => $followUp->sale_reference,
                'user' => [
                    'id' => (int) $followUp->user_id,
                    'name' => $followUp->user_name,
                ],
            ])
            ->all();
    }

    private function customer(int $tenantId, int $customerId): object
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

        return $customer;
    }

    private function formatCustomer(object $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'document_type' => $customer->document_type,
            'document_number' => $customer->document_number,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'credit_limit' => $customer->credit_limit !== null ? round((float) $customer->credit_limit, 2) : null,
            'credit_days' => $customer->credit_days !== null ? (int) $customer->credit_days : null,
            'block_on_overdue' => (bool) $customer->block_on_overdue,
            'status' => $customer->status,
        ];
    }

    private function limit(int $limit): int
    {
        return max(1, min($limit, 100));
    }
}
