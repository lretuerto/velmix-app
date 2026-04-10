<?php

namespace App\Services\Sales;

use App\Services\Audit\TenantActivityLogService;
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

        $followUps = $this->followUpsForReceivable($tenantId, $receivableId);

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
            'latest_follow_up' => $followUps[0] ?? null,
            'follow_ups' => $followUps,
        ];
    }

    public function followUps(int $tenantId, int $receivableId): array
    {
        $this->loadReceivable($tenantId, $receivableId);

        return $this->followUpsForReceivable($tenantId, $receivableId);
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
                ->first(['id', 'sale_id', 'total_amount', 'paid_amount', 'outstanding_amount', 'status']);

            if ($receivable === null) {
                throw new HttpException(404, 'Sale receivable not found.');
            }

            if ($receivable->status === 'paid') {
                throw new HttpException(422, 'Sale receivable is already fully paid.');
            }

            if ($amount > (float) $receivable->outstanding_amount) {
                throw new HttpException(422, 'Payment amount exceeds outstanding amount.');
            }

            $cashSessionId = null;

            if ($paymentMethod === 'cash') {
                $cashSessionId = DB::table('cash_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->value('id');

                if ($cashSessionId === null) {
                    throw new HttpException(422, 'Cash receivable payment requires an open cash session.');
                }
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

            $cashMovementId = null;

            if ($cashSessionId !== null) {
                $cashMovementId = DB::table('cash_movements')->insertGetId([
                    'tenant_id' => $tenantId,
                    'cash_session_id' => $cashSessionId,
                    'created_by_user_id' => $userId,
                    'type' => 'receivable_in',
                    'amount' => $amount,
                    'reference' => $reference,
                    'notes' => 'Receivable payment for sale '.$receivable->sale_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'sales',
                'sales.receivable.payment_registered',
                'sale_receivable',
                $receivable->id,
                'Cobranza registrada para cuenta por cobrar '.$receivable->id,
                [
                    'sale_receivable_id' => $receivable->id,
                    'sale_id' => $receivable->sale_id,
                    'amount' => round($amount, 2),
                    'payment_method' => $paymentMethod,
                    'reference' => $reference,
                    'cash_movement_id' => $cashMovementId,
                    'status' => $newStatus,
                    'outstanding_amount' => $newOutstandingAmount,
                ],
            );

            return [
                'payment_id' => $paymentId,
                'sale_receivable_id' => $receivable->id,
                'amount' => round($amount, 2),
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                'cash_movement_id' => $cashMovementId,
                'paid_amount' => $newPaidAmount,
                'outstanding_amount' => $newOutstandingAmount,
                'status' => $newStatus,
            ];
        });
    }

    public function addFollowUp(
        int $tenantId,
        int $userId,
        int $receivableId,
        string $type,
        string $note,
        ?float $promisedAmount = null,
        ?string $promisedAt = null
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        $type = trim($type);
        $note = trim($note);

        if (! in_array($type, ['note', 'promise'], true)) {
            throw new HttpException(422, 'Follow up type is invalid.');
        }

        if ($note === '') {
            throw new HttpException(422, 'Follow up note is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $receivableId, $type, $note, $promisedAmount, $promisedAt) {
            $receivable = $this->loadReceivable($tenantId, $receivableId, true);

            if ($type === 'promise' && $promisedAt === null) {
                throw new HttpException(422, 'Promise follow up requires promised_at.');
            }

            if ($promisedAmount !== null && $promisedAmount <= 0) {
                throw new HttpException(422, 'Promised amount must be valid.');
            }

            if ($promisedAmount !== null && $promisedAmount > (float) $receivable->outstanding_amount) {
                throw new HttpException(422, 'Promised amount exceeds outstanding amount.');
            }

            $normalizedPromisedAt = $type === 'promise' && $promisedAt !== null
                ? CarbonImmutable::parse($promisedAt)->startOfDay()->toDateTimeString()
                : null;

            $followUpId = DB::table('sale_receivable_follow_ups')->insertGetId([
                'tenant_id' => $tenantId,
                'sale_receivable_id' => $receivableId,
                'user_id' => $userId,
                'type' => $type,
                'note' => $note,
                'promised_amount' => $type === 'promise' ? $promisedAmount : null,
                'outstanding_snapshot' => $type === 'promise' ? round((float) $receivable->outstanding_amount, 2) : null,
                'promised_at' => $normalizedPromisedAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'sales',
                'sales.receivable.follow_up_added',
                'sale_receivable',
                $receivableId,
                'Seguimiento registrado para cuenta por cobrar '.$receivableId,
                [
                    'sale_receivable_id' => $receivableId,
                    'type' => $type,
                    'note' => $note,
                    'promised_amount' => $type === 'promise' ? $promisedAmount : null,
                    'promised_at' => $normalizedPromisedAt,
                    'outstanding_snapshot' => $type === 'promise' ? round((float) $receivable->outstanding_amount, 2) : null,
                    'follow_up_id' => $followUpId,
                ],
            );

            return $this->followUpById($followUpId);
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

    private function loadReceivable(int $tenantId, int $receivableId, bool $lockForUpdate = false): object
    {
        $query = DB::table('sale_receivables')
            ->where('tenant_id', $tenantId)
            ->where('id', $receivableId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $receivable = $query->first(['id', 'outstanding_amount']);

        if ($receivable === null) {
            throw new HttpException(404, 'Sale receivable not found.');
        }

        return $receivable;
    }

    private function followUpsForReceivable(int $tenantId, int $receivableId): array
    {
        return DB::table('sale_receivable_follow_ups')
            ->join('users', 'users.id', '=', 'sale_receivable_follow_ups.user_id')
            ->where('sale_receivable_follow_ups.tenant_id', $tenantId)
            ->where('sale_receivable_follow_ups.sale_receivable_id', $receivableId)
            ->orderByDesc('sale_receivable_follow_ups.id')
            ->get([
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

    private function followUpById(int $followUpId): array
    {
        $followUp = DB::table('sale_receivable_follow_ups')
            ->join('users', 'users.id', '=', 'sale_receivable_follow_ups.user_id')
            ->where('sale_receivable_follow_ups.id', $followUpId)
            ->first([
                'sale_receivable_follow_ups.id',
                'sale_receivable_follow_ups.type',
                'sale_receivable_follow_ups.note',
                'sale_receivable_follow_ups.promised_amount',
                'sale_receivable_follow_ups.outstanding_snapshot',
                'sale_receivable_follow_ups.promised_at',
                'sale_receivable_follow_ups.created_at',
                'users.id as user_id',
                'users.name as user_name',
            ]);

        if ($followUp === null) {
            throw new HttpException(404, 'Sale receivable follow up not found.');
        }

        return $this->formatFollowUp($followUp);
    }

    private function formatFollowUp(object $followUp): array
    {
        return [
            'id' => $followUp->id,
            'type' => $followUp->type,
            'note' => $followUp->note,
            'promised_amount' => $followUp->promised_amount !== null ? (float) $followUp->promised_amount : null,
            'outstanding_snapshot' => $followUp->outstanding_snapshot !== null ? (float) $followUp->outstanding_snapshot : null,
            'promised_at' => $followUp->promised_at,
            'created_at' => $followUp->created_at,
            'user' => [
                'id' => $followUp->user_id,
                'name' => $followUp->user_name,
            ],
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
