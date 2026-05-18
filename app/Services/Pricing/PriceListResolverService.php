<?php

namespace App\Services\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PriceListResolverService
{
    public function resolveForQuote(
        int $tenantId,
        array $items,
        ?int $customerId = null,
        string $channel = 'retail'
    ): array {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (trim($channel) === '') {
            throw new HttpException(422, 'Quote channel is required.');
        }

        $normalizedItems = $this->normalizeItems($items);
        $customer = $this->resolveCustomer($tenantId, $customerId);
        $pricingAt = now();
        $resolvedPriceList = $this->resolvePriceList($tenantId, $customer?->id, $channel, $pricingAt);
        $products = $this->resolveProducts($tenantId, $normalizedItems);
        $priceListItems = $this->resolvePriceListItems(
            (int) $resolvedPriceList['price_list']['id'],
            $normalizedItems,
            $pricingAt,
        );

        $resolvedItems = $normalizedItems
            ->map(function (array $item) use ($products, $priceListItems, $resolvedPriceList) {
                $product = $products->get($item['product_id']);
                $priceListItem = $priceListItems->get($item['product_id']);

                if ($priceListItem === null) {
                    throw new HttpException(
                        422,
                        sprintf(
                            'Price list %s does not define an active base price for product %s.',
                            $resolvedPriceList['price_list']['code'],
                            $product->sku,
                        ),
                    );
                }

                $baseUnitPrice = round((float) $priceListItem->unit_price, 2);
                $lineTotal = round($baseUnitPrice * $item['requested_quantity'], 2);

                return [
                    'product_id' => (int) $product->id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'requested_quantity' => $item['requested_quantity'],
                    'resolved_price_list_item_id' => (int) $priceListItem->id,
                    'base_unit_price' => $baseUnitPrice,
                    'final_unit_price' => $baseUnitPrice,
                    'line_discount_amount' => 0.0,
                    'line_total' => $lineTotal,
                    'min_unit_price' => $priceListItem->min_unit_price !== null ? round((float) $priceListItem->min_unit_price, 2) : null,
                    'max_discount_pct' => $priceListItem->max_discount_pct !== null ? round((float) $priceListItem->max_discount_pct, 2) : null,
                    'commercial_context' => [
                        'product' => [
                            'id' => (int) $product->id,
                            'sku' => $product->sku,
                            'name' => $product->name,
                            'status' => $product->status,
                            'commercial_status' => $product->commercial_status,
                            'laboratory_supplier_id' => $product->laboratory_supplier_id !== null ? (int) $product->laboratory_supplier_id : null,
                        ],
                        'price_source' => $resolvedPriceList['source'],
                        'price_list' => [
                            'id' => (int) $resolvedPriceList['price_list']['id'],
                            'code' => $resolvedPriceList['price_list']['code'],
                            'channel' => $resolvedPriceList['price_list']['channel'],
                            'currency' => $resolvedPriceList['price_list']['currency'],
                        ],
                    ],
                    'adjustments' => [[
                        'adjustment_type' => 'base_price',
                        'description' => sprintf('Precio base de lista %s', $resolvedPriceList['price_list']['code']),
                        'promotion_id' => null,
                        'promotion_rule_id' => null,
                        'sponsor_supplier_id' => null,
                        'quantity' => (float) $item['requested_quantity'],
                        'unit_delta' => 0.0,
                        'total_delta' => 0.0,
                        'metadata' => [
                            'price_source' => $resolvedPriceList['source'],
                            'price_list_id' => (int) $resolvedPriceList['price_list']['id'],
                            'price_list_item_id' => (int) $priceListItem->id,
                            'unit_price' => $baseUnitPrice,
                        ],
                    ]],
                ];
            })
            ->values()
            ->all();

        return [
            'channel' => $channel,
            'customer' => $customer !== null ? [
                'id' => (int) $customer->id,
                'document_type' => $customer->document_type,
                'document_number' => $customer->document_number,
                'name' => $customer->name,
                'status' => $customer->status,
            ] : null,
            'price_list' => $resolvedPriceList['price_list'],
            'items' => $resolvedItems,
            'warnings' => [],
            'resolved_at' => $pricingAt->toAtomString(),
        ];
    }

    private function normalizeItems(array $items): Collection
    {
        if ($items === []) {
            throw new HttpException(422, 'At least one quote item is required.');
        }

        return collect($items)
            ->values()
            ->map(function (array $item, int $index) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? $item['requested_quantity'] ?? 0);

                if ($productId <= 0) {
                    throw new HttpException(422, sprintf('Quote item #%d must include a valid product_id.', $index + 1));
                }

                if ($quantity <= 0) {
                    throw new HttpException(422, sprintf('Quote item #%d must include a valid quantity.', $index + 1));
                }

                return [
                    'product_id' => $productId,
                    'requested_quantity' => $quantity,
                ];
            });
    }

    private function resolveCustomer(int $tenantId, ?int $customerId): ?object
    {
        if ($customerId === null) {
            return null;
        }

        $customer = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('id', $customerId)
            ->first([
                'id',
                'document_type',
                'document_number',
                'name',
                'status',
            ]);

        if ($customer === null) {
            throw new HttpException(404, 'Customer not found.');
        }

        return $customer;
    }

    private function resolvePriceList(int $tenantId, ?int $customerId, string $channel, object $pricingAt): array
    {
        if ($customerId !== null) {
            $customerAssigned = DB::table('customer_price_list_assignments')
                ->join('price_lists', 'price_lists.id', '=', 'customer_price_list_assignments.price_list_id')
                ->where('customer_price_list_assignments.tenant_id', $tenantId)
                ->where('customer_price_list_assignments.customer_id', $customerId)
                ->where('customer_price_list_assignments.status', 'active')
                ->where('price_lists.tenant_id', $tenantId)
                ->where('price_lists.status', 'active')
                ->where('price_lists.channel', $channel)
                ->where(function ($query) use ($pricingAt) {
                    $query->whereNull('customer_price_list_assignments.starts_at')
                        ->orWhere('customer_price_list_assignments.starts_at', '<=', $pricingAt);
                })
                ->where(function ($query) use ($pricingAt) {
                    $query->whereNull('customer_price_list_assignments.ends_at')
                        ->orWhere('customer_price_list_assignments.ends_at', '>=', $pricingAt);
                })
                ->where(function ($query) use ($pricingAt) {
                    $query->whereNull('price_lists.starts_at')
                        ->orWhere('price_lists.starts_at', '<=', $pricingAt);
                })
                ->where(function ($query) use ($pricingAt) {
                    $query->whereNull('price_lists.ends_at')
                        ->orWhere('price_lists.ends_at', '>=', $pricingAt);
                })
                ->orderBy('customer_price_list_assignments.priority')
                ->orderBy('price_lists.priority')
                ->orderBy('price_lists.id')
                ->first([
                    'price_lists.id',
                    'price_lists.code',
                    'price_lists.name',
                    'price_lists.status',
                    'price_lists.channel',
                    'price_lists.currency',
                    'price_lists.priority',
                    'price_lists.is_default',
                ]);

            if ($customerAssigned !== null) {
                return [
                    'source' => 'customer_assignment',
                    'price_list' => $this->mapPriceList($customerAssigned),
                ];
            }
        }

        $defaultList = DB::table('price_lists')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('channel', $channel)
            ->where('is_default', true)
            ->where(function ($query) use ($pricingAt) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $pricingAt);
            })
            ->where(function ($query) use ($pricingAt) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $pricingAt);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->first([
                'id',
                'code',
                'name',
                'status',
                'channel',
                'currency',
                'priority',
                'is_default',
            ]);

        if ($defaultList === null) {
            throw new HttpException(422, 'No active default price list is available for the requested channel.');
        }

        return [
            'source' => 'tenant_default',
            'price_list' => $this->mapPriceList($defaultList),
        ];
    }

    private function resolveProducts(int $tenantId, Collection $items): Collection
    {
        $productIds = $items->pluck('product_id')->unique()->values()->all();

        $products = DB::table('products')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)
            ->get([
                'id',
                'sku',
                'name',
                'status',
                'commercial_status',
                'laboratory_supplier_id',
            ])
            ->keyBy('id');

        foreach ($productIds as $productId) {
            $product = $products->get($productId);

            if ($product === null) {
                throw new HttpException(404, sprintf('Product %d not found.', $productId));
            }

            if ($product->status !== 'active') {
                throw new HttpException(422, sprintf('Product %s is not active.', $product->sku));
            }

            if ($product->commercial_status !== 'active') {
                throw new HttpException(422, sprintf('Product %s is not commercially active.', $product->sku));
            }
        }

        return $products;
    }

    private function resolvePriceListItems(int $priceListId, Collection $items, object $pricingAt): Collection
    {
        return DB::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->whereIn('product_id', $items->pluck('product_id')->unique()->values()->all())
            ->where('status', 'active')
            ->where(function ($query) use ($pricingAt) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $pricingAt);
            })
            ->where(function ($query) use ($pricingAt) {
                $query->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $pricingAt);
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get([
                'id',
                'product_id',
                'unit_price',
                'min_unit_price',
                'max_discount_pct',
            ])
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => $rows->first());
    }

    private function mapPriceList(object $priceList): array
    {
        return [
            'id' => (int) $priceList->id,
            'code' => $priceList->code,
            'name' => $priceList->name,
            'status' => $priceList->status,
            'channel' => $priceList->channel,
            'currency' => $priceList->currency,
            'priority' => (int) $priceList->priority,
            'is_default' => (bool) $priceList->is_default,
        ];
    }
}
