<?php

namespace App\Services\Billing;

use App\Services\Cash\CashSessionService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditNoteService
{
    public function createFromSale(int $tenantId, int $userId, int $saleId, string $reason): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'Credit note reason is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $saleId, $reason) {
            $sale = DB::table('sales')
                ->where('tenant_id', $tenantId)
                ->where('id', $saleId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'reference',
                    'status',
                    'payment_method',
                    'total_amount',
                ]);

            if ($sale === null) {
                throw new HttpException(404, 'Sale not found.');
            }

            if ($sale->status !== 'completed') {
                throw new HttpException(422, 'Only completed sales can be credited.');
            }

            $voucher = DB::table('electronic_vouchers')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'series', 'number', 'type']);

            if ($voucher === null) {
                throw new HttpException(422, 'Sale requires a voucher before issuing a credit note.');
            }

            $existingNote = DB::table('sale_credit_notes')
                ->where('sale_id', $saleId)
                ->exists();

            if ($existingNote) {
                throw new HttpException(422, 'Sale already has a credit note.');
            }

            $receivable = DB::table('sale_receivables')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'paid_amount']);

            $paymentMethods = [];

            if ($receivable !== null) {
                $paymentMethods = DB::table('sale_receivable_payments')
                    ->where('sale_receivable_id', $receivable->id)
                    ->orderBy('id')
                    ->pluck('payment_method')
                    ->unique()
                    ->values()
                    ->all();
            }

            $refundAmount = $this->resolveRefundAmount((string) $sale->payment_method, (float) ($receivable->paid_amount ?? 0), (float) $sale->total_amount);
            $refundPaymentMethod = $this->resolveRefundPaymentMethod((string) $sale->payment_method, $paymentMethods, $refundAmount);
            $cashSessionId = $this->resolveCashSessionId($tenantId, $userId, $refundAmount, $refundPaymentMethod);

            $series = 'NC01';
            $nextNumber = ((int) DB::table('sale_credit_notes')
                ->where('tenant_id', $tenantId)
                ->where('series', $series)
                ->max('number')) + 1;

            $creditNoteId = DB::table('sale_credit_notes')->insertGetId([
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'electronic_voucher_id' => $voucher->id,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
                'reason' => $reason,
                'total_amount' => $sale->total_amount,
                'refunded_amount' => $refundAmount,
                'refund_payment_method' => $refundPaymentMethod,
                'sunat_ticket' => null,
                'rejection_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->reverseSaleStock($tenantId, $saleId, (string) $sale->reference);

            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => 'credited',
                    'credited_by_user_id' => $userId,
                    'credit_reason' => $reason,
                    'credited_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($receivable !== null) {
                DB::table('sale_receivables')
                    ->where('id', $receivable->id)
                    ->update([
                        'status' => 'credited',
                        'outstanding_amount' => 0,
                        'updated_at' => now(),
                    ]);
            }

            $refundId = null;
            $cashMovementId = null;

            if ($refundAmount > 0 && $refundPaymentMethod !== null) {
                if ($refundPaymentMethod === 'cash') {
                    $cashMovementId = DB::table('cash_movements')->insertGetId([
                        'tenant_id' => $tenantId,
                        'cash_session_id' => $cashSessionId,
                        'created_by_user_id' => $userId,
                        'type' => 'credit_note_refund',
                        'amount' => $refundAmount,
                        'reference' => 'CN-'.$sale->reference,
                        'notes' => 'Refund for credit note '.$creditNoteId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $refundId = DB::table('sale_refunds')->insertGetId([
                    'tenant_id' => $tenantId,
                    'sale_id' => $saleId,
                    'sale_credit_note_id' => $creditNoteId,
                    'cash_session_id' => $cashSessionId,
                    'user_id' => $userId,
                    'payment_method' => $refundPaymentMethod,
                    'amount' => $refundAmount,
                    'reference' => 'CN-'.$sale->reference,
                    'notes' => 'Refund generated by credit note',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('outbox_events')->insert([
                'tenant_id' => $tenantId,
                'aggregate_type' => 'sale_credit_note',
                'aggregate_id' => $creditNoteId,
                'event_type' => 'credit_note.created',
                'payload' => json_encode([
                    'credit_note_id' => $creditNoteId,
                    'sale_id' => $saleId,
                    'series' => $series,
                    'number' => $nextNumber,
                ], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $creditNoteId,
                'sale_id' => $saleId,
                'voucher_id' => $voucher->id,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
                'reason' => $reason,
                'total_amount' => round((float) $sale->total_amount, 2),
                'refunded_amount' => round($refundAmount, 2),
                'refund_payment_method' => $refundPaymentMethod,
                'refund_id' => $refundId,
                'cash_movement_id' => $cashMovementId,
            ];
        });
    }

    public function detail(int $tenantId, int $creditNoteId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $creditNote = DB::table('sale_credit_notes')
            ->join('sales', 'sales.id', '=', 'sale_credit_notes.sale_id')
            ->join('electronic_vouchers', 'electronic_vouchers.id', '=', 'sale_credit_notes.electronic_voucher_id')
            ->where('sale_credit_notes.tenant_id', $tenantId)
            ->where('sale_credit_notes.id', $creditNoteId)
            ->first([
                'sale_credit_notes.id',
                'sale_credit_notes.sale_id',
                'sale_credit_notes.electronic_voucher_id',
                'sale_credit_notes.series',
                'sale_credit_notes.number',
                'sale_credit_notes.status',
                'sale_credit_notes.reason',
                'sale_credit_notes.total_amount',
                'sale_credit_notes.refunded_amount',
                'sale_credit_notes.refund_payment_method',
                'sale_credit_notes.sunat_ticket',
                'sale_credit_notes.rejection_reason',
                'sales.reference as sale_reference',
                'electronic_vouchers.series as voucher_series',
                'electronic_vouchers.number as voucher_number',
                'electronic_vouchers.type as voucher_type',
            ]);

        if ($creditNote === null) {
            throw new HttpException(404, 'Credit note not found.');
        }

        $refund = DB::table('sale_refunds')
            ->where('sale_credit_note_id', $creditNoteId)
            ->first(['id', 'cash_session_id', 'payment_method', 'amount', 'reference', 'created_at']);

        return [
            'id' => $creditNote->id,
            'sale_id' => $creditNote->sale_id,
            'sale_reference' => $creditNote->sale_reference,
            'voucher' => [
                'id' => $creditNote->electronic_voucher_id,
                'type' => $creditNote->voucher_type,
                'series' => $creditNote->voucher_series,
                'number' => $creditNote->voucher_number,
            ],
            'series' => $creditNote->series,
            'number' => $creditNote->number,
            'status' => $creditNote->status,
            'reason' => $creditNote->reason,
            'total_amount' => (float) $creditNote->total_amount,
            'refunded_amount' => (float) $creditNote->refunded_amount,
            'refund_payment_method' => $creditNote->refund_payment_method,
            'sunat_ticket' => $creditNote->sunat_ticket,
            'rejection_reason' => $creditNote->rejection_reason,
            'refund' => $refund !== null ? [
                'id' => $refund->id,
                'cash_session_id' => $refund->cash_session_id,
                'payment_method' => $refund->payment_method,
                'amount' => (float) $refund->amount,
                'reference' => $refund->reference,
                'created_at' => $refund->created_at,
            ] : null,
        ];
    }

    private function resolveRefundAmount(string $paymentMethod, float $paidAmount, float $saleTotal): float
    {
        if ($paymentMethod === 'credit') {
            return round($paidAmount, 2);
        }

        return round($saleTotal, 2);
    }

    private function resolveRefundPaymentMethod(string $paymentMethod, array $paymentMethods, float $refundAmount): ?string
    {
        if ($refundAmount <= 0) {
            return null;
        }

        if ($paymentMethod !== 'credit') {
            return $paymentMethod;
        }

        if ($paymentMethods === []) {
            return null;
        }

        if (count($paymentMethods) > 1) {
            throw new HttpException(422, 'Mixed receivable payments cannot be credited automatically.');
        }

        return $paymentMethods[0];
    }

    private function resolveCashSessionId(int $tenantId, int $userId, float $refundAmount, ?string $refundPaymentMethod): ?int
    {
        if ($refundAmount <= 0 || $refundPaymentMethod !== 'cash') {
            return null;
        }

        $session = DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first(['id']);

        if ($session === null) {
            throw new HttpException(422, 'Cash refunds require an open cash session.');
        }

        $summary = (new CashSessionService())->current($tenantId);

        if ($refundAmount > (float) $summary['expected_amount']) {
            throw new HttpException(422, 'Cash refund exceeds available cash in session.');
        }

        return $session->id;
    }

    private function reverseSaleStock(int $tenantId, int $saleId, string $reference): void
    {
        $items = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->get(['lot_id', 'product_id', 'quantity']);

        foreach ($items as $item) {
            $lot = DB::table('lots')
                ->where('id', $item->lot_id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id', 'stock_quantity']);

            if ($lot === null) {
                throw new HttpException(404, 'Lot not found during credit note.');
            }

            DB::table('lots')
                ->where('id', $lot->id)
                ->update([
                    'stock_quantity' => (int) $lot->stock_quantity + (int) $item->quantity,
                    'updated_at' => now(),
                ]);

            DB::table('stock_movements')->insert([
                'tenant_id' => $tenantId,
                'lot_id' => $item->lot_id,
                'product_id' => $item->product_id,
                'sale_id' => $saleId,
                'type' => 'credit_note_reversal',
                'quantity' => (int) $item->quantity,
                'reference' => $reference.'-CN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
