<?php

namespace App\Services\Pricing;

use App\Services\Sales\PosSaleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PricingCheckoutService
{
    public function __construct(
        private readonly PosSaleService $posSaleService,
    ) {}

    public function execute(
        int $tenantId,
        int $userId,
        int $quoteId,
        string $quoteHash,
        array $lineInputs = [],
        ?string $dueAt = null,
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context and authenticated user are required.');
        }

        if (trim($quoteHash) === '') {
            throw new HttpException(422, 'Quote hash is required.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $quoteId, $quoteHash, $lineInputs, $dueAt) {
            $quote = DB::table('pricing_quotes')
                ->where('tenant_id', $tenantId)
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'customer_id',
                    'price_list_id',
                    'payment_method',
                    'status',
                    'quote_hash',
                    'expires_at',
                    'sale_id',
                    'subtotal_amount',
                    'discount_amount',
                    'total_amount',
                    'currency',
                ]);

            if ($quote === null) {
                throw new HttpException(404, 'Pricing quote not found.');
            }

            if ($quote->quote_hash !== $quoteHash) {
                throw new HttpException(409, 'Pricing quote hash does not match the latest snapshot.');
            }

            if ($quote->status === 'consumed') {
                throw new HttpException(409, 'Pricing quote was already consumed.');
            }

            if ($quote->status !== 'quoted') {
                throw new HttpException(422, 'Pricing quote is not available for checkout.');
            }

            if (strtotime((string) $quote->expires_at) < now()->getTimestamp()) {
                DB::table('pricing_quotes')
                    ->where('id', $quoteId)
                    ->update([
                        'status' => 'expired',
                        'updated_at' => now(),
                    ]);

                throw new HttpException(422, 'Pricing quote has expired. Generate a new quote before checkout.');
            }

            $quoteItems = DB::table('pricing_quote_items')
                ->where('pricing_quote_id', $quoteId)
                ->orderBy('id')
                ->get([
                    'id',
                    'product_id',
                    'requested_quantity',
                    'base_unit_price',
                    'final_unit_price',
                    'line_total',
                ])
                ->keyBy('id');

            if ($quoteItems->isEmpty()) {
                throw new HttpException(422, 'Pricing quote does not contain any checkoutable items.');
            }

            $this->assertQuoteTotalsMatchItems($quote, $quoteItems);

            $lineInputsByQuoteItemId = $this->normalizeLineInputs($quoteItems, $lineInputs);

            $salePayloadItems = $quoteItems
                ->map(function (object $quoteItem) use ($lineInputsByQuoteItemId) {
                    $lineInput = $lineInputsByQuoteItemId->get((int) $quoteItem->id, []);

                    return [
                        'quote_item_id' => (int) $quoteItem->id,
                        'product_id' => (int) $quoteItem->product_id,
                        'quantity' => (int) $quoteItem->requested_quantity,
                        'unit_price' => round((float) $quoteItem->final_unit_price, 2),
                        'line_total' => round((float) $quoteItem->line_total, 2),
                        'prescription_code' => $lineInput['prescription_code'] ?? null,
                        'approval_code' => $lineInput['approval_code'] ?? null,
                    ];
                })
                ->values()
                ->all();

            $sale = $this->posSaleService->execute(
                $tenantId,
                $userId,
                $salePayloadItems,
                (string) $quote->payment_method,
                $quote->customer_id !== null ? (int) $quote->customer_id : null,
                $dueAt,
            );

            $this->assertSaleMatchesQuote($quote, $sale);

            $adjustmentsByQuoteItemId = DB::table('pricing_quote_adjustments')
                ->whereIn('pricing_quote_item_id', $quoteItems->keys()->all())
                ->orderBy('id')
                ->get([
                    'id',
                    'pricing_quote_item_id',
                    'promotion_id',
                    'promotion_rule_id',
                    'adjustment_type',
                    'description',
                    'sponsor_supplier_id',
                    'quantity',
                    'unit_delta',
                    'total_delta',
                    'metadata',
                ])
                ->groupBy('pricing_quote_item_id');

            $this->persistSaleItemPricingComponents(
                $quoteId,
                $quoteItems,
                $adjustmentsByQuoteItemId,
                $sale['items'],
            );

            DB::table('pricing_quotes')
                ->where('id', $quoteId)
                ->update([
                    'status' => 'consumed',
                    'sale_id' => $sale['sale_id'],
                    'updated_at' => now(),
                ]);

            return [
                'quote' => [
                    'id' => (int) $quote->id,
                    'status' => 'consumed',
                    'quote_hash' => $quote->quote_hash,
                    'sale_id' => (int) $sale['sale_id'],
                    'summary' => [
                        'subtotal_amount' => round((float) $quote->subtotal_amount, 2),
                        'discount_amount' => round((float) $quote->discount_amount, 2),
                        'total_amount' => round((float) $quote->total_amount, 2),
                    ],
                    'currency' => $quote->currency,
                ],
                'sale' => $sale,
            ];
        });
    }

    private function normalizeLineInputs(Collection $quoteItems, array $lineInputs): Collection
    {
        $normalized = collect($lineInputs)
            ->values()
            ->map(function (array $lineInput, int $index) use ($quoteItems) {
                $quoteItemId = (int) ($lineInput['quote_item_id'] ?? 0);

                if ($quoteItemId <= 0) {
                    throw new HttpException(422, sprintf('Checkout line input #%d must include a valid quote_item_id.', $index + 1));
                }

                if (! $quoteItems->has($quoteItemId)) {
                    throw new HttpException(422, sprintf('Quote item %d does not belong to this pricing quote.', $quoteItemId));
                }

                return [
                    'quote_item_id' => $quoteItemId,
                    'prescription_code' => $this->normalizeOptionalString($lineInput['prescription_code'] ?? null),
                    'approval_code' => $this->normalizeOptionalString($lineInput['approval_code'] ?? null),
                ];
            });

        if ($normalized->pluck('quote_item_id')->duplicates()->isNotEmpty()) {
            throw new HttpException(422, 'Checkout line inputs must not repeat the same quote_item_id.');
        }

        return $normalized->keyBy('quote_item_id');
    }

    private function assertQuoteTotalsMatchItems(object $quote, Collection $quoteItems): void
    {
        $subtotalAmount = round($quoteItems->sum(fn (object $item) => (float) $item->base_unit_price * (int) $item->requested_quantity), 2);
        $totalAmount = round($quoteItems->sum(fn (object $item) => (float) $item->line_total), 2);
        $discountAmount = round($subtotalAmount - $totalAmount, 2);

        if (
            $subtotalAmount === round((float) $quote->subtotal_amount, 2)
            && $discountAmount === round((float) $quote->discount_amount, 2)
            && $totalAmount === round((float) $quote->total_amount, 2)
        ) {
            return;
        }

        throw new HttpException(409, 'Pricing quote totals do not match persisted quote items.');
    }

    private function assertSaleMatchesQuote(object $quote, array $sale): void
    {
        $saleTotal = round((float) ($sale['total_amount'] ?? -1), 2);
        $quoteTotal = round((float) $quote->total_amount, 2);
        $salePaymentMethod = (string) ($sale['payment_method'] ?? '');
        $saleCustomerId = isset($sale['customer']['id']) ? (int) $sale['customer']['id'] : null;
        $quoteCustomerId = $quote->customer_id !== null ? (int) $quote->customer_id : null;

        if (
            $saleTotal === $quoteTotal
            && $salePaymentMethod === (string) $quote->payment_method
            && $saleCustomerId === $quoteCustomerId
        ) {
            return;
        }

        throw new HttpException(409, 'Checkout sale result does not match the pricing quote snapshot.');
    }

    private function persistSaleItemPricingComponents(
        int $quoteId,
        Collection $quoteItems,
        Collection $adjustmentsByQuoteItemId,
        array $soldItems,
    ): void {
        foreach ($soldItems as $soldItem) {
            $quoteItemId = isset($soldItem['quote_item_id']) ? (int) $soldItem['quote_item_id'] : 0;

            if ($quoteItemId <= 0) {
                throw new HttpException(409, 'Checkout sale item is missing quote item mapping.');
            }

            $quoteItem = $quoteItems->get($quoteItemId);

            if ($quoteItem === null) {
                throw new HttpException(409, 'Checkout sale item references an unknown quote item.');
            }

            $allocations = collect($soldItem['allocations'] ?? [])
                ->map(function (array $allocation): array {
                    $saleItemId = (int) ($allocation['sale_item_id'] ?? 0);
                    $quantity = (int) ($allocation['quantity'] ?? 0);

                    if ($saleItemId <= 0 || $quantity <= 0) {
                        throw new HttpException(409, 'Checkout sale item allocation is invalid.');
                    }

                    return [
                        'sale_item_id' => $saleItemId,
                        'quantity' => $quantity,
                    ];
                })
                ->values()
                ->all();

            if ($allocations === []) {
                throw new HttpException(409, 'Checkout sale item is missing stock allocation mapping.');
            }

            foreach ($adjustmentsByQuoteItemId->get($quoteItemId, collect()) as $adjustment) {
                $metadata = $this->decodeJson($adjustment->metadata);
                $totalAmount = $adjustment->adjustment_type === 'base_price'
                    ? round((float) $quoteItem->base_unit_price * (int) $quoteItem->requested_quantity, 2)
                    : round((float) $adjustment->total_delta, 2);
                $allocatedTotals = $this->splitAmountAcrossAllocations(
                    $allocations,
                    (int) $quoteItem->requested_quantity,
                    $totalAmount,
                );

                foreach ($allocations as $allocation) {
                    $allocatedTotal = $allocatedTotals[$allocation['sale_item_id']] ?? 0.0;
                    $unitAmount = $allocation['quantity'] > 0
                        ? round($allocatedTotal / $allocation['quantity'], 2)
                        : 0.0;

                    DB::table('sale_item_pricing_components')->insert([
                        'sale_item_id' => $allocation['sale_item_id'],
                        'pricing_quote_item_id' => $quoteItemId,
                        'promotion_id' => $adjustment->promotion_id !== null ? (int) $adjustment->promotion_id : null,
                        'promotion_rule_id' => $adjustment->promotion_rule_id !== null ? (int) $adjustment->promotion_rule_id : null,
                        'component_type' => $adjustment->adjustment_type,
                        'description' => $adjustment->description,
                        'sponsor_supplier_id' => $adjustment->sponsor_supplier_id !== null ? (int) $adjustment->sponsor_supplier_id : null,
                        'unit_amount' => $unitAmount,
                        'total_amount' => $allocatedTotal,
                        'metadata' => $this->encodeJson(array_merge($metadata, [
                            'pricing_quote_id' => $quoteId,
                            'pricing_quote_adjustment_id' => (int) $adjustment->id,
                            'quote_item_requested_quantity' => (int) $quoteItem->requested_quantity,
                            'quote_adjustment_quantity' => round((float) $adjustment->quantity, 2),
                        ])),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function splitAmountAcrossAllocations(array $allocations, int $requestedQuantity, float $amount): array
    {
        if ($allocations === []) {
            return [];
        }

        $remaining = round($amount, 2);
        $lastIndex = array_key_last($allocations);
        $allocated = [];

        foreach ($allocations as $index => $allocation) {
            if ($index === $lastIndex) {
                $allocatedAmount = $remaining;
            } else {
                $ratio = $requestedQuantity > 0 ? ($allocation['quantity'] / $requestedQuantity) : 0;
                $allocatedAmount = round($amount * $ratio, 2);
                $remaining = round($remaining - $allocatedAmount, 2);
            }

            $allocated[(int) $allocation['sale_item_id']] = $allocatedAmount;
        }

        return $allocated;
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function decodeJson(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
