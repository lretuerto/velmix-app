<?php

namespace App\Services\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PromotionEligibilityService
{
    public function eligibleForQuote(int $tenantId, array $resolvedQuote, string $paymentMethod): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        if (! in_array($paymentMethod, ['cash', 'card', 'transfer', 'credit'], true)) {
            throw new HttpException(422, 'Payment method is invalid.');
        }

        $items = collect($resolvedQuote['items'] ?? [])->values();
        $priceListId = (int) ($resolvedQuote['price_list']['id'] ?? 0);
        $customerId = $resolvedQuote['customer']['id'] ?? null;
        $channel = (string) ($resolvedQuote['channel'] ?? 'retail');
        $pricingAt = now();

        if ($items->isEmpty()) {
            return [];
        }

        $promotions = DB::table('promotions')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'promotions.sponsor_supplier_id')
            ->where('promotions.tenant_id', $tenantId)
            ->where('promotions.status', 'active')
            ->where(function ($query) use ($channel) {
                $query->where('promotions.channel', $channel)
                    ->orWhere('promotions.channel', 'mixed');
            })
            ->where('promotions.starts_at', '<=', $pricingAt)
            ->where(function ($query) use ($pricingAt) {
                $query->whereNull('promotions.ends_at')
                    ->orWhere('promotions.ends_at', '>=', $pricingAt);
            })
            ->where(function ($query) {
                $query->whereNull('promotions.budget_cap')
                    ->orWhereColumn('promotions.budget_used', '<', 'promotions.budget_cap');
            })
            ->orderBy('promotions.priority')
            ->orderBy('promotions.id')
            ->get([
                'promotions.id',
                'promotions.code',
                'promotions.name',
                'promotions.status',
                'promotions.priority',
                'promotions.stack_mode',
                'promotions.stop_further_processing',
                'promotions.requires_customer',
                'promotions.allowed_payment_methods',
                'promotions.sponsor_supplier_id',
                'suppliers.name as sponsor_supplier_name',
            ]);

        if ($promotions->isEmpty()) {
            return [];
        }

        $promotionIds = $promotions->pluck('id')->all();
        $targetsByPromotion = DB::table('promotion_targets')
            ->whereIn('promotion_id', $promotionIds)
            ->orderBy('id')
            ->get(['id', 'promotion_id', 'target_type', 'target_id', 'exclude'])
            ->groupBy('promotion_id');
        $audiencesByPromotion = DB::table('promotion_audiences')
            ->whereIn('promotion_id', $promotionIds)
            ->orderBy('id')
            ->get(['id', 'promotion_id', 'audience_type', 'audience_id'])
            ->groupBy('promotion_id');
        $rulesByPromotion = DB::table('promotion_rules')
            ->whereIn('promotion_id', $promotionIds)
            ->where('status', 'active')
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'promotion_id', 'rule_type', 'scope', 'config', 'priority'])
            ->groupBy('promotion_id');

        return $promotions
            ->map(function (object $promotion) use ($items, $customerId, $priceListId, $paymentMethod, $targetsByPromotion, $audiencesByPromotion, $rulesByPromotion) {
                if (! $this->promotionAllowsPaymentMethod($promotion->allowed_payment_methods, $paymentMethod)) {
                    return null;
                }

                if ((bool) $promotion->requires_customer && $customerId === null) {
                    return null;
                }

                $audiences = collect($audiencesByPromotion->get($promotion->id, collect()));

                if (! $this->promotionMatchesAudience($audiences, $customerId, $priceListId)) {
                    return null;
                }

                $matchingItemKeys = $this->matchingItemKeys(
                    $items,
                    collect($targetsByPromotion->get($promotion->id, collect())),
                    $priceListId,
                );

                if ($matchingItemKeys === []) {
                    return null;
                }

                $rules = collect($rulesByPromotion->get($promotion->id, collect()))
                    ->map(function (object $rule) use ($matchingItemKeys) {
                        return [
                            'id' => (int) $rule->id,
                            'rule_type' => $rule->rule_type,
                            'scope' => $rule->scope,
                            'priority' => (int) $rule->priority,
                            'config' => $this->decodeJson($rule->config),
                            'matched_item_keys' => $matchingItemKeys,
                        ];
                    })
                    ->values()
                    ->all();

                if ($rules === []) {
                    return null;
                }

                return [
                    'id' => (int) $promotion->id,
                    'code' => $promotion->code,
                    'name' => $promotion->name,
                    'priority' => (int) $promotion->priority,
                    'stack_mode' => $promotion->stack_mode,
                    'stop_further_processing' => (bool) $promotion->stop_further_processing,
                    'requires_customer' => (bool) $promotion->requires_customer,
                    'sponsor_supplier' => $promotion->sponsor_supplier_id !== null ? [
                        'id' => (int) $promotion->sponsor_supplier_id,
                        'name' => $promotion->sponsor_supplier_name,
                    ] : null,
                    'rules' => $rules,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function promotionAllowsPaymentMethod(?string $allowedPaymentMethods, string $paymentMethod): bool
    {
        if ($allowedPaymentMethods === null) {
            return true;
        }

        $methods = $this->decodeJson($allowedPaymentMethods);

        if ($methods === []) {
            return true;
        }

        return in_array($paymentMethod, $methods, true);
    }

    private function promotionMatchesAudience(Collection $audiences, ?int $customerId, int $priceListId): bool
    {
        if ($audiences->isEmpty()) {
            return true;
        }

        return $audiences->contains(function (object $audience) use ($customerId, $priceListId) {
            return match ($audience->audience_type) {
                'all' => true,
                'walk_in' => $customerId === null,
                'customer' => $customerId !== null && (int) $audience->audience_id === $customerId,
                'customer_price_list' => $priceListId > 0 && (int) $audience->audience_id === $priceListId,
                default => false,
            };
        });
    }

    private function matchingItemKeys(Collection $items, Collection $targets, int $priceListId): array
    {
        if ($targets->isEmpty()) {
            return $items->keys()->map(fn ($key) => (int) $key)->values()->all();
        }

        $included = [];
        $excluded = [];

        foreach ($targets as $target) {
            $matches = $this->targetMatches($items, $target->target_type, $target->target_id, $priceListId);

            if ((bool) $target->exclude) {
                $excluded = array_merge($excluded, $matches);
            } else {
                $included = array_merge($included, $matches);
            }
        }

        if ($included === []) {
            return [];
        }

        return array_values(array_diff(array_values(array_unique($included)), array_values(array_unique($excluded))));
    }

    private function targetMatches(Collection $items, string $targetType, mixed $targetId, int $priceListId): array
    {
        return match ($targetType) {
            'all_products' => $items->keys()->map(fn ($key) => (int) $key)->values()->all(),
            'product' => $items->filter(fn (array $item) => (int) $item['product_id'] === (int) $targetId)->keys()->map(fn ($key) => (int) $key)->values()->all(),
            'laboratory' => $items->filter(fn (array $item) => (int) ($item['commercial_context']['product']['laboratory_supplier_id'] ?? 0) === (int) $targetId)->keys()->map(fn ($key) => (int) $key)->values()->all(),
            'price_list' => $priceListId > 0 && $priceListId === (int) $targetId
                ? $items->keys()->map(fn ($key) => (int) $key)->values()->all()
                : [],
            default => [],
        };
    }

    private function decodeJson(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
