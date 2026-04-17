<?php

namespace App\Services\Billing;

use App\Services\Audit\TenantActivityLogService;
use App\Services\Cash\CashSessionService;
use App\Services\Inventory\LotStockMutationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditNoteService
{
    public function createFromSale(int $tenantId, int $userId, int $saleId, string $reason, array $requestedItems = []): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context or authenticated user missing.');
        }

        if (trim($reason) === '') {
            throw new HttpException(422, 'Credit note reason is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $saleId, $reason, $requestedItems) {
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

            if (! in_array($sale->status, ['completed', 'credited'], true)) {
                throw new HttpException(422, 'Sale cannot be credited.');
            }

            $voucher = DB::table('electronic_vouchers')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'series', 'number', 'type']);

            if ($voucher === null) {
                throw new HttpException(422, 'Sale requires a voucher before issuing a credit note.');
            }

            $saleItems = DB::table('sale_items')
                ->where('sale_id', $saleId)
                ->orderBy('id')
                ->get([
                    'id',
                    'lot_id',
                    'product_id',
                    'quantity',
                    'unit_price',
                    'line_total',
                ]);

            if ($saleItems->isEmpty()) {
                throw new HttpException(422, 'Sale has no items to credit.');
            }

            $receivable = DB::table('sale_receivables')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'total_amount', 'paid_amount', 'outstanding_amount']);

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

            $creditedQuantities = DB::table('sale_credit_note_items')
                ->join('sale_credit_notes', 'sale_credit_notes.id', '=', 'sale_credit_note_items.sale_credit_note_id')
                ->where('sale_credit_notes.sale_id', $saleId)
                ->selectRaw('sale_item_id, COALESCE(SUM(quantity), 0) as credited_quantity')
                ->groupBy('sale_item_id')
                ->pluck('credited_quantity', 'sale_item_id');

            $priorCreditedTotal = round((float) DB::table('sale_credit_notes')
                ->where('sale_id', $saleId)
                ->sum('total_amount'), 2);

            $targetItems = $this->resolveTargetItems($saleItems, $creditedQuantities, $requestedItems);
            $creditedTotal = round(collect($targetItems)->sum('line_total'), 2);

            if ($creditedTotal <= 0) {
                throw new HttpException(422, 'Credit note total must be valid.');
            }

            $priorRefundedAmount = (float) DB::table('sale_refunds')
                ->where('sale_id', $saleId)
                ->sum('amount');

            $refundAmount = $this->resolveRefundAmount(
                (string) $sale->payment_method,
                (float) ($receivable->paid_amount ?? 0),
                (float) $sale->total_amount,
                $creditedTotal,
                $priorRefundedAmount,
            );
            $refundPaymentMethod = $this->resolveRefundPaymentMethod((string) $sale->payment_method, $paymentMethods, $refundAmount);
            $cashSessionId = $this->resolveCashSessionId($tenantId, $userId, $refundAmount, $refundPaymentMethod);

            $series = 'NC01';
            $nextNumber = app(BillingDocumentNumberService::class)->nextCreditNoteNumber($tenantId, $series);

            $creditNoteId = DB::table('sale_credit_notes')->insertGetId([
                'tenant_id' => $tenantId,
                'sale_id' => $saleId,
                'electronic_voucher_id' => $voucher->id,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
                'reason' => $reason,
                'total_amount' => $creditedTotal,
                'refunded_amount' => $refundAmount,
                'refund_payment_method' => $refundPaymentMethod,
                'sunat_ticket' => null,
                'rejection_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->reverseSaleStock($tenantId, $saleId, (string) $sale->reference, $targetItems);
            $this->createCreditNoteItems($creditNoteId, $targetItems);

            $remainingSaleTotal = round((float) $sale->total_amount - $priorCreditedTotal - $creditedTotal, 2);
            $saleStatus = $remainingSaleTotal <= 0 ? 'credited' : 'completed';

            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => $saleStatus,
                    'credited_by_user_id' => $userId,
                    'credit_reason' => $reason,
                    'credited_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($receivable !== null) {
                $newReceivableTotal = round(max((float) $receivable->total_amount - $creditedTotal, 0), 2);
                $newPaidAmount = round(max((float) $receivable->paid_amount - $refundAmount, 0), 2);
                $newOutstandingAmount = round(max($newReceivableTotal - $newPaidAmount, 0), 2);
                $newReceivableStatus = $newReceivableTotal <= 0
                    ? 'credited'
                    : ($newOutstandingAmount <= 0
                        ? 'paid'
                        : ($newPaidAmount > 0 ? 'partial_paid' : 'pending'));

                DB::table('sale_receivables')
                    ->where('id', $receivable->id)
                    ->update([
                        'total_amount' => $newReceivableTotal,
                        'paid_amount' => $newPaidAmount,
                        'outstanding_amount' => $newOutstandingAmount,
                        'status' => $newReceivableStatus,
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

            $payloadSnapshot = app(BillingDocumentPayloadService::class)->createForCreditNote($tenantId, $creditNoteId, $userId);

            DB::table('outbox_events')->insert([
                'tenant_id' => $tenantId,
                'aggregate_type' => 'sale_credit_note',
                'aggregate_id' => $creditNoteId,
                'event_type' => 'credit_note.created',
                'payload' => json_encode(array_merge([
                    'credit_note_id' => $creditNoteId,
                    'sale_id' => $saleId,
                    'series' => $series,
                    'number' => $nextNumber,
                ], app(BillingDocumentPayloadService::class)->outboxEnvelope($payloadSnapshot)), JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(TenantActivityLogService::class)->record(
                $tenantId,
                $userId,
                'billing',
                'billing.credit_note.issued',
                'sale_credit_note',
                $creditNoteId,
                'Nota de credito '.$series.'-'.$nextNumber.' emitida',
                [
                    'sale_credit_note_id' => $creditNoteId,
                    'sale_id' => $saleId,
                    'voucher_id' => $voucher->id,
                    'reason' => $reason,
                    'total_amount' => $creditedTotal,
                    'refunded_amount' => round($refundAmount, 2),
                    'refund_payment_method' => $refundPaymentMethod,
                    'item_count' => count($targetItems),
                ],
            );

            return [
                'id' => $creditNoteId,
                'sale_id' => $saleId,
                'voucher_id' => $voucher->id,
                'series' => $series,
                'number' => $nextNumber,
                'status' => 'pending',
                'reason' => $reason,
                'total_amount' => $creditedTotal,
                'refunded_amount' => round($refundAmount, 2),
                'refund_payment_method' => $refundPaymentMethod,
                'refund_id' => $refundId,
                'cash_movement_id' => $cashMovementId,
                'items' => array_map(fn (array $item) => [
                    'sale_item_id' => $item['sale_item_id'],
                    'quantity' => $item['quantity'],
                    'line_total' => $item['line_total'],
                ], $targetItems),
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

        $items = DB::table('sale_credit_note_items')
            ->join('products', 'products.id', '=', 'sale_credit_note_items.product_id')
            ->join('lots', 'lots.id', '=', 'sale_credit_note_items.lot_id')
            ->where('sale_credit_note_items.sale_credit_note_id', $creditNoteId)
            ->orderBy('sale_credit_note_items.id')
            ->get([
                'sale_credit_note_items.sale_item_id',
                'sale_credit_note_items.quantity',
                'sale_credit_note_items.unit_price',
                'sale_credit_note_items.line_total',
                'products.sku as product_sku',
                'lots.code as lot_code',
            ])
            ->map(fn (object $item) => [
                'sale_item_id' => $item->sale_item_id,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'product_sku' => $item->product_sku,
                'lot_code' => $item->lot_code,
            ])
            ->all();

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
            'items' => $items,
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

    private function resolveRefundAmount(string $paymentMethod, float $paidAmount, float $saleTotal, float $creditedTotal, float $priorRefundedAmount): float
    {
        $availableRefundable = $paymentMethod === 'credit'
            ? max($paidAmount - $priorRefundedAmount, 0)
            : max($saleTotal - $priorRefundedAmount, 0);

        if ($availableRefundable <= 0) {
            return 0.0;
        }

        if ($paymentMethod === 'credit') {
            return round(min($creditedTotal, $availableRefundable), 2);
        }

        return round(min($creditedTotal, $availableRefundable), 2);
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

    private function reverseSaleStock(int $tenantId, int $saleId, string $reference, array $targetItems): void
    {
        foreach ($targetItems as $item) {
            $lot = DB::table('lots')
                ->where('id', $item['lot_id'])
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first(['id', 'stock_quantity']);

            if ($lot === null) {
                throw new HttpException(404, 'Lot not found during credit note.');
            }

            app(LotStockMutationService::class)->incrementLockedLot(
                $lot,
                (int) $item['quantity'],
                'Lot not found during credit note.',
            );

            DB::table('stock_movements')->insert([
                'tenant_id' => $tenantId,
                'lot_id' => $item['lot_id'],
                'product_id' => $item['product_id'],
                'sale_id' => $saleId,
                'type' => 'credit_note_reversal',
                'quantity' => (int) $item['quantity'],
                'reference' => $reference.'-CN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function resolveTargetItems($saleItems, $creditedQuantities, array $requestedItems): array
    {
        $saleItemsById = $saleItems->keyBy('id');

        $remainingItems = $saleItems->mapWithKeys(function (object $item) use ($creditedQuantities) {
            $creditedQuantity = (int) ($creditedQuantities[$item->id] ?? 0);
            $remainingQuantity = (int) $item->quantity - $creditedQuantity;

            return [$item->id => max($remainingQuantity, 0)];
        });

        if ($requestedItems === []) {
            $resolved = [];

            foreach ($saleItems as $item) {
                $remainingQuantity = (int) ($remainingItems[$item->id] ?? 0);

                if ($remainingQuantity <= 0) {
                    continue;
                }

                $resolved[] = [
                    'sale_item_id' => $item->id,
                    'lot_id' => $item->lot_id,
                    'product_id' => $item->product_id,
                    'quantity' => $remainingQuantity,
                    'unit_price' => round((float) $item->unit_price, 2),
                    'line_total' => round($remainingQuantity * (float) $item->unit_price, 2),
                ];
            }

            if ($resolved === []) {
                throw new HttpException(422, 'Sale has no remaining quantities to credit.');
            }

            return $resolved;
        }

        $resolved = [];
        $requestedByItem = [];

        foreach ($requestedItems as $requestedItem) {
            $saleItemId = (int) ($requestedItem['sale_item_id'] ?? 0);
            $quantity = (int) ($requestedItem['quantity'] ?? 0);

            if ($saleItemId <= 0 || $quantity <= 0) {
                throw new HttpException(422, 'Credit note items are invalid.');
            }

            $saleItem = $saleItemsById->get($saleItemId);

            if ($saleItem === null) {
                throw new HttpException(404, 'Sale item not found for credit note.');
            }

            $requestedByItem[$saleItemId] = ($requestedByItem[$saleItemId] ?? 0) + $quantity;
            $remainingQuantity = (int) ($remainingItems[$saleItemId] ?? 0);

            if ($requestedByItem[$saleItemId] > $remainingQuantity) {
                throw new HttpException(422, 'Credit note quantity exceeds remaining sale quantity.');
            }

            $resolved[] = [
                'sale_item_id' => $saleItem->id,
                'lot_id' => $saleItem->lot_id,
                'product_id' => $saleItem->product_id,
                'quantity' => $quantity,
                'unit_price' => round((float) $saleItem->unit_price, 2),
                'line_total' => round($quantity * (float) $saleItem->unit_price, 2),
            ];
        }

        return $resolved;
    }

    private function createCreditNoteItems(int $creditNoteId, array $targetItems): void
    {
        DB::table('sale_credit_note_items')->insert(array_map(fn (array $item) => [
            'sale_credit_note_id' => $creditNoteId,
            'sale_item_id' => $item['sale_item_id'],
            'lot_id' => $item['lot_id'],
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $targetItems));
    }
}
