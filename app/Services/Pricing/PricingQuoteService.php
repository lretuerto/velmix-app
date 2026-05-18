<?php

namespace App\Services\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PricingQuoteService
{
    public function __construct(
        private readonly PriceListResolverService $priceListResolverService,
        private readonly PromotionEngineService $promotionEngineService,
    ) {}

    public function create(
        int $tenantId,
        int $userId,
        array $items,
        string $paymentMethod = 'cash',
        ?int $customerId = null,
        string $channel = 'retail'
    ): array {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new HttpException(403, 'Tenant context and authenticated user are required.');
        }

        if (! in_array($paymentMethod, ['cash', 'card', 'transfer', 'credit'], true)) {
            throw new HttpException(422, 'Payment method is invalid.');
        }

        $userAttachedToTenant = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->exists();

        if (! $userAttachedToTenant) {
            throw new HttpException(403, 'Authenticated user is not attached to tenant.');
        }

        $resolved = $this->priceListResolverService->resolveForQuote(
            $tenantId,
            $items,
            $customerId,
            $channel,
        );
        $priced = $this->promotionEngineService->apply($tenantId, $resolved, $paymentMethod);

        return DB::transaction(function () use ($tenantId, $userId, $paymentMethod, $resolved, $priced) {
            $subtotalAmount = round(collect($priced['items'])->sum(fn (array $item) => $item['base_unit_price'] * $item['requested_quantity']), 2);
            $discountAmount = round(collect($priced['items'])->sum('line_discount_amount'), 2);
            $totalAmount = round(collect($priced['items'])->sum('line_total'), 2);
            $expiresAt = now()->addMinutes(10);
            $quoteHash = $this->generateQuoteHash($tenantId, $userId);

            $quoteId = DB::table('pricing_quotes')->insertGetId([
                'tenant_id' => $tenantId,
                'customer_id' => $resolved['customer']['id'] ?? null,
                'price_list_id' => $resolved['price_list']['id'],
                'channel' => $resolved['channel'],
                'payment_method' => $paymentMethod,
                'status' => 'quoted',
                'quote_hash' => $quoteHash,
                'subtotal_amount' => $subtotalAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'currency' => $resolved['price_list']['currency'],
                'expires_at' => $expiresAt,
                'created_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $persistedItems = [];

            foreach ($priced['items'] as $item) {
                $quoteItemId = DB::table('pricing_quote_items')->insertGetId([
                    'pricing_quote_id' => $quoteId,
                    'product_id' => $item['product_id'],
                    'requested_quantity' => $item['requested_quantity'],
                    'resolved_price_list_item_id' => $item['resolved_price_list_item_id'],
                    'base_unit_price' => $item['base_unit_price'],
                    'final_unit_price' => $item['final_unit_price'],
                    'line_discount_amount' => $item['line_discount_amount'],
                    'line_total' => $item['line_total'],
                    'commercial_context' => $this->encodeJson($item['commercial_context']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $adjustments = [];

                foreach ($item['adjustments'] as $adjustment) {
                    $adjustmentId = DB::table('pricing_quote_adjustments')->insertGetId([
                        'pricing_quote_item_id' => $quoteItemId,
                        'promotion_id' => $adjustment['promotion_id'],
                        'promotion_rule_id' => $adjustment['promotion_rule_id'],
                        'adjustment_type' => $adjustment['adjustment_type'],
                        'description' => $adjustment['description'],
                        'sponsor_supplier_id' => $adjustment['sponsor_supplier_id'],
                        'quantity' => $adjustment['quantity'],
                        'unit_delta' => $adjustment['unit_delta'],
                        'total_delta' => $adjustment['total_delta'],
                        'metadata' => $this->encodeJson($adjustment['metadata']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $adjustments[] = [
                        'id' => $adjustmentId,
                        'type' => $adjustment['adjustment_type'],
                        'description' => $adjustment['description'],
                        'promotion_id' => $adjustment['promotion_id'],
                        'promotion_rule_id' => $adjustment['promotion_rule_id'],
                        'promotion_code' => $adjustment['metadata']['promotion_code'] ?? null,
                        'sponsor_supplier' => $adjustment['metadata']['sponsor_supplier'] ?? null,
                        'quantity' => round((float) $adjustment['quantity'], 2),
                        'unit_delta' => round((float) $adjustment['unit_delta'], 2),
                        'total_delta' => round((float) $adjustment['total_delta'], 2),
                        'metadata' => $adjustment['metadata'],
                    ];
                }

                $persistedItems[] = [
                    'id' => $quoteItemId,
                    'product_id' => $item['product_id'],
                    'product_sku' => $item['product_sku'],
                    'product_name' => $item['product_name'],
                    'requested_quantity' => $item['requested_quantity'],
                    'resolved_price_list_item_id' => $item['resolved_price_list_item_id'],
                    'base_unit_price' => $item['base_unit_price'],
                    'final_unit_price' => $item['final_unit_price'],
                    'line_discount_amount' => $item['line_discount_amount'],
                    'line_total' => $item['line_total'],
                    'commercial_context' => $item['commercial_context'],
                    'adjustments' => $adjustments,
                ];
            }

            return [
                'id' => $quoteId,
                'status' => 'quoted',
                'quote_hash' => $quoteHash,
                'channel' => $resolved['channel'],
                'payment_method' => $paymentMethod,
                'expires_at' => $expiresAt->toAtomString(),
                'currency' => $resolved['price_list']['currency'],
                'customer' => $resolved['customer'],
                'price_list' => $resolved['price_list'],
                'summary' => [
                    'subtotal_amount' => $subtotalAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                ],
                'items' => $persistedItems,
                'warnings' => $priced['warnings'],
                'applied_promotions' => $priced['applied_promotions'],
            ];
        });
    }

    public function detail(int $tenantId, int $quoteId): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $quote = DB::table('pricing_quotes')
            ->leftJoin('customers', 'customers.id', '=', 'pricing_quotes.customer_id')
            ->leftJoin('price_lists', 'price_lists.id', '=', 'pricing_quotes.price_list_id')
            ->where('pricing_quotes.tenant_id', $tenantId)
            ->where('pricing_quotes.id', $quoteId)
            ->first([
                'pricing_quotes.id',
                'pricing_quotes.status',
                'pricing_quotes.quote_hash',
                'pricing_quotes.channel',
                'pricing_quotes.payment_method',
                'pricing_quotes.subtotal_amount',
                'pricing_quotes.discount_amount',
                'pricing_quotes.total_amount',
                'pricing_quotes.currency',
                'pricing_quotes.expires_at',
                'pricing_quotes.created_at',
                'pricing_quotes.updated_at',
                'customers.id as customer_id',
                'customers.document_type as customer_document_type',
                'customers.document_number as customer_document_number',
                'customers.name as customer_name',
                'customers.status as customer_status',
                'price_lists.id as price_list_id',
                'price_lists.code as price_list_code',
                'price_lists.name as price_list_name',
                'price_lists.status as price_list_status',
                'price_lists.channel as price_list_channel',
                'price_lists.currency as price_list_currency',
                'price_lists.priority as price_list_priority',
                'price_lists.is_default as price_list_is_default',
            ]);

        if ($quote === null) {
            throw new HttpException(404, 'Pricing quote not found.');
        }

        $items = DB::table('pricing_quote_items')
            ->join('products', 'products.id', '=', 'pricing_quote_items.product_id')
            ->where('pricing_quote_items.pricing_quote_id', $quoteId)
            ->orderBy('pricing_quote_items.id')
            ->get([
                'pricing_quote_items.id',
                'pricing_quote_items.product_id',
                'pricing_quote_items.requested_quantity',
                'pricing_quote_items.resolved_price_list_item_id',
                'pricing_quote_items.base_unit_price',
                'pricing_quote_items.final_unit_price',
                'pricing_quote_items.line_discount_amount',
                'pricing_quote_items.line_total',
                'pricing_quote_items.commercial_context',
                'products.sku as product_sku',
                'products.name as product_name',
            ]);

        $adjustmentsByItem = DB::table('pricing_quote_adjustments')
            ->leftJoin('promotions', 'promotions.id', '=', 'pricing_quote_adjustments.promotion_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'pricing_quote_adjustments.sponsor_supplier_id')
            ->whereIn('pricing_quote_adjustments.pricing_quote_item_id', $items->pluck('id')->all())
            ->orderBy('pricing_quote_adjustments.id')
            ->get([
                'pricing_quote_adjustments.id',
                'pricing_quote_adjustments.pricing_quote_item_id',
                'pricing_quote_adjustments.promotion_id',
                'pricing_quote_adjustments.promotion_rule_id',
                'pricing_quote_adjustments.adjustment_type',
                'pricing_quote_adjustments.description',
                'pricing_quote_adjustments.sponsor_supplier_id',
                'pricing_quote_adjustments.quantity',
                'pricing_quote_adjustments.unit_delta',
                'pricing_quote_adjustments.total_delta',
                'pricing_quote_adjustments.metadata',
                'promotions.code as promotion_code',
                'promotions.name as promotion_name',
                'suppliers.name as sponsor_supplier_name',
            ])
            ->groupBy('pricing_quote_item_id');

        $mappedItems = $items->map(function (object $item) use ($adjustmentsByItem) {
            $adjustments = collect($adjustmentsByItem->get($item->id, collect()))
                ->map(function (object $adjustment) {
                    $metadata = $this->decodeJson($adjustment->metadata);

                    return [
                        'id' => (int) $adjustment->id,
                        'type' => $adjustment->adjustment_type,
                        'description' => $adjustment->description,
                        'promotion_id' => $adjustment->promotion_id !== null ? (int) $adjustment->promotion_id : null,
                        'promotion_rule_id' => $adjustment->promotion_rule_id !== null ? (int) $adjustment->promotion_rule_id : null,
                        'promotion_code' => $adjustment->promotion_code ?? ($metadata['promotion_code'] ?? null),
                        'promotion_name' => $adjustment->promotion_name ?? ($metadata['promotion_name'] ?? null),
                        'sponsor_supplier' => $adjustment->sponsor_supplier_id !== null ? [
                            'id' => (int) $adjustment->sponsor_supplier_id,
                            'name' => $adjustment->sponsor_supplier_name ?? ($metadata['sponsor_supplier']['name'] ?? null),
                        ] : ($metadata['sponsor_supplier'] ?? null),
                        'quantity' => round((float) $adjustment->quantity, 2),
                        'unit_delta' => round((float) $adjustment->unit_delta, 2),
                        'total_delta' => round((float) $adjustment->total_delta, 2),
                        'metadata' => $metadata,
                    ];
                })
                ->values()
                ->all();

            return [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'product_sku' => $item->product_sku,
                'product_name' => $item->product_name,
                'requested_quantity' => (int) $item->requested_quantity,
                'resolved_price_list_item_id' => $item->resolved_price_list_item_id !== null ? (int) $item->resolved_price_list_item_id : null,
                'base_unit_price' => round((float) $item->base_unit_price, 2),
                'final_unit_price' => round((float) $item->final_unit_price, 2),
                'line_discount_amount' => round((float) $item->line_discount_amount, 2),
                'line_total' => round((float) $item->line_total, 2),
                'commercial_context' => $this->decodeJson($item->commercial_context),
                'adjustments' => $adjustments,
            ];
        })->values();

        return [
            'id' => (int) $quote->id,
            'status' => $quote->status,
            'quote_hash' => $quote->quote_hash,
            'channel' => $quote->channel,
            'payment_method' => $quote->payment_method,
            'expires_at' => $quote->expires_at,
            'currency' => $quote->currency,
            'created_at' => $quote->created_at,
            'updated_at' => $quote->updated_at,
            'customer' => $quote->customer_id !== null ? [
                'id' => (int) $quote->customer_id,
                'document_type' => $quote->customer_document_type,
                'document_number' => $quote->customer_document_number,
                'name' => $quote->customer_name,
                'status' => $quote->customer_status,
            ] : null,
            'price_list' => $quote->price_list_id !== null ? [
                'id' => (int) $quote->price_list_id,
                'code' => $quote->price_list_code,
                'name' => $quote->price_list_name,
                'status' => $quote->price_list_status,
                'channel' => $quote->price_list_channel,
                'currency' => $quote->price_list_currency,
                'priority' => (int) $quote->price_list_priority,
                'is_default' => (bool) $quote->price_list_is_default,
            ] : null,
            'summary' => [
                'subtotal_amount' => round((float) $quote->subtotal_amount, 2),
                'discount_amount' => round((float) $quote->discount_amount, 2),
                'total_amount' => round((float) $quote->total_amount, 2),
            ],
            'items' => $mappedItems->all(),
            'warnings' => [],
            'applied_promotions' => $this->summarizeAppliedPromotions($mappedItems),
        ];
    }

    private function generateQuoteHash(int $tenantId, int $userId): string
    {
        return 'sha256:'.hash(
            'sha256',
            implode('|', [
                $tenantId,
                $userId,
                now()->format('YmdHis.u'),
                Str::ulid()->toBase32(),
            ]),
        );
    }

    private function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function decodeJson(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function summarizeAppliedPromotions(Collection $items): array
    {
        return $items
            ->flatMap(fn (array $item) => $item['adjustments'])
            ->filter(fn (array $adjustment) => $adjustment['type'] === 'promotion_discount' && $adjustment['promotion_id'] !== null)
            ->groupBy('promotion_id')
            ->map(function (Collection $adjustments) {
                $first = $adjustments->first();

                return [
                    'id' => (int) $first['promotion_id'],
                    'code' => $first['promotion_code'],
                    'name' => $first['promotion_name'],
                    'discount_amount' => round($adjustments->sum(fn (array $adjustment) => abs((float) $adjustment['total_delta'])), 2),
                    'sponsor_supplier' => $first['sponsor_supplier'],
                ];
            })
            ->values()
            ->all();
    }
}
