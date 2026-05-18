<?php

namespace App\Services\Pricing;

use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PromotionEngineService
{
    public function __construct(
        private readonly PromotionEligibilityService $promotionEligibilityService,
    ) {}

    public function apply(int $tenantId, array $resolvedQuote, string $paymentMethod): array
    {
        if ($tenantId <= 0) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        $eligiblePromotions = $this->promotionEligibilityService->eligibleForQuote($tenantId, $resolvedQuote, $paymentMethod);
        $workingItems = collect($resolvedQuote['items'] ?? [])->values()->map(fn (array $item) => $item);
        $warnings = collect($resolvedQuote['warnings'] ?? []);
        $appliedPromotions = [];

        foreach (collect($eligiblePromotions)->groupBy('priority')->sortKeys() as $priorityPromotions) {
            $exclusive = collect($priorityPromotions)->where('stack_mode', 'exclusive')->values();

            if ($exclusive->isNotEmpty()) {
                $bestExclusive = $this->bestPromotionApplication($exclusive, $workingItems);

                if ($bestExclusive !== null) {
                    $workingItems = $bestExclusive['items'];
                    $warnings = $warnings->merge($bestExclusive['warnings']);
                    $appliedPromotions[] = $bestExclusive['promotion'];
                    break;
                }
            }

            $bestPriceOnly = collect($priorityPromotions)->where('stack_mode', 'best_price_only')->values();

            if ($bestPriceOnly->isNotEmpty()) {
                $bestPromotion = $this->bestPromotionApplication($bestPriceOnly, $workingItems);

                if ($bestPromotion !== null) {
                    $workingItems = $bestPromotion['items'];
                    $warnings = $warnings->merge($bestPromotion['warnings']);
                    $appliedPromotions[] = $bestPromotion['promotion'];

                    if ($bestPromotion['promotion']['stop_further_processing']) {
                        break;
                    }
                }
            }

            $stopFurtherProcessing = false;

            foreach (collect($priorityPromotions)->where('stack_mode', 'stackable')->sortBy('id')->values() as $promotion) {
                $applied = $this->evaluatePromotion($promotion, $workingItems);

                if ($applied === null) {
                    continue;
                }

                $workingItems = $applied['items'];
                $warnings = $warnings->merge($applied['warnings']);
                $appliedPromotions[] = $applied['promotion'];

                if ($applied['promotion']['stop_further_processing']) {
                    $stopFurtherProcessing = true;
                    break;
                }
            }

            if ($stopFurtherProcessing) {
                break;
            }
        }

        return [
            'items' => $workingItems
                ->map(function (array $item) {
                    $baseLineTotal = round($item['base_unit_price'] * $item['requested_quantity'], 2);
                    $lineTotal = max(round($baseLineTotal - $item['line_discount_amount'], 2), 0);

                    $item['line_total'] = $lineTotal;
                    $item['final_unit_price'] = $item['requested_quantity'] > 0
                        ? round($lineTotal / $item['requested_quantity'], 2)
                        : 0.0;
                    $item['adjustments'] = array_values($item['adjustments']);

                    return $item;
                })
                ->values()
                ->all(),
            'warnings' => $warnings->filter()->values()->all(),
            'applied_promotions' => $appliedPromotions,
        ];
    }

    private function bestPromotionApplication(Collection $promotions, Collection $items): ?array
    {
        $best = null;

        foreach ($promotions as $promotion) {
            $evaluated = $this->evaluatePromotion($promotion, $items);

            if ($evaluated === null) {
                continue;
            }

            if ($best === null || $evaluated['promotion']['discount_amount'] > $best['promotion']['discount_amount']) {
                $best = $evaluated;
            }
        }

        return $best;
    }

    private function evaluatePromotion(array $promotion, Collection $items): ?array
    {
        $workingItems = $items->map(fn (array $item) => $item)->values();
        $warnings = [];
        $discountAmount = 0.0;
        $appliedRuleCount = 0;

        foreach (collect($promotion['rules'])->sortBy('priority')->values() as $rule) {
            $applied = match ($rule['rule_type']) {
                'fixed_unit_price' => $this->applyFixedUnitPrice($workingItems, $promotion, $rule),
                'percent_off' => $this->applyPercentOff($workingItems, $promotion, $rule),
                'amount_off' => $this->applyAmountOff($workingItems, $promotion, $rule),
                'buy_x_pay_y' => $this->applyBuyXPayY($workingItems, $promotion, $rule),
                'buy_x_get_y_free' => $this->applyBuyXGetYFree($workingItems, $promotion, $rule),
                'second_unit_percent_off' => $this->applySecondUnitPercentOff($workingItems, $promotion, $rule),
                'tiered_quantity_price' => $this->applyTieredQuantityPrice($workingItems, $promotion, $rule),
                'cart_amount_percent_off' => $this->applyCartAmountPercentOff($workingItems, $promotion, $rule),
                default => null,
            };

            if ($applied === null) {
                $warnings[] = sprintf(
                    'Promotion %s has unsupported rule_type %s in this engine version.',
                    $promotion['code'],
                    $rule['rule_type'],
                );

                continue;
            }

            if ($applied['discount_amount'] <= 0) {
                continue;
            }

            $discountAmount += $applied['discount_amount'];
            $appliedRuleCount += $applied['applied_rule_count'];
        }

        if ($discountAmount <= 0 || $appliedRuleCount === 0) {
            return null;
        }

        return [
            'items' => $workingItems,
            'warnings' => $warnings,
            'promotion' => [
                'id' => $promotion['id'],
                'code' => $promotion['code'],
                'name' => $promotion['name'],
                'stack_mode' => $promotion['stack_mode'],
                'stop_further_processing' => $promotion['stop_further_processing'],
                'discount_amount' => round($discountAmount, 2),
                'sponsor_supplier' => $promotion['sponsor_supplier'],
            ],
        ];
    }

    private function applyFixedUnitPrice(Collection $items, array $promotion, array $rule): ?array
    {
        $targetUnitPrice = round((float) ($rule['config']['unit_price'] ?? -1), 2);
        $minQuantity = max((int) ($rule['config']['min_quantity'] ?? 1), 1);

        if ($targetUnitPrice < 0) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($targetUnitPrice, $minQuantity) {
                if ($item['requested_quantity'] < $minQuantity || $targetUnitPrice >= $item['final_unit_price']) {
                    return null;
                }

                $quantity = $item['requested_quantity'];
                $totalDelta = round(($targetUnitPrice - $item['final_unit_price']) * $quantity, 2);

                return [
                    'quantity' => (float) $quantity,
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'fixed_unit_price',
                        'target_unit_price' => $targetUnitPrice,
                    ],
                ];
            },
            'Precio fijo promocional %s',
        );
    }

    private function applyPercentOff(Collection $items, array $promotion, array $rule): ?array
    {
        $percent = round((float) ($rule['config']['percent_off'] ?? 0), 2);
        $minQuantity = max((int) ($rule['config']['min_quantity'] ?? 1), 1);

        if ($percent <= 0) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($percent, $minQuantity) {
                if ($item['requested_quantity'] < $minQuantity) {
                    return null;
                }

                $currentLineTotal = round($item['final_unit_price'] * $item['requested_quantity'], 2);
                $totalDelta = round(-1 * $currentLineTotal * ($percent / 100), 2);

                return [
                    'quantity' => (float) $item['requested_quantity'],
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'percent_off',
                        'percent_off' => $percent,
                    ],
                ];
            },
            'Descuento porcentual %s',
        );
    }

    private function applyAmountOff(Collection $items, array $promotion, array $rule): ?array
    {
        $amountOff = round((float) ($rule['config']['amount_off'] ?? 0), 2);
        $perUnit = (bool) ($rule['config']['per_unit'] ?? false);
        $minQuantity = max((int) ($rule['config']['min_quantity'] ?? 1), 1);

        if ($amountOff <= 0) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($amountOff, $perUnit, $minQuantity) {
                if ($item['requested_quantity'] < $minQuantity) {
                    return null;
                }

                $totalDelta = $perUnit
                    ? round(-1 * $amountOff * $item['requested_quantity'], 2)
                    : round(-1 * $amountOff, 2);

                return [
                    'quantity' => (float) ($perUnit ? $item['requested_quantity'] : 1),
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'amount_off',
                        'amount_off' => $amountOff,
                        'per_unit' => $perUnit,
                    ],
                ];
            },
            'Descuento fijo %s',
        );
    }

    private function applyBuyXPayY(Collection $items, array $promotion, array $rule): ?array
    {
        $buyQuantity = max((int) ($rule['config']['buy_quantity'] ?? 0), 0);
        $payQuantity = max((int) ($rule['config']['pay_quantity'] ?? 0), 0);

        if ($buyQuantity <= 0 || $payQuantity <= 0 || $payQuantity >= $buyQuantity) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($buyQuantity, $payQuantity) {
                $groups = intdiv($item['requested_quantity'], $buyQuantity);
                $freeUnits = $groups * ($buyQuantity - $payQuantity);

                if ($freeUnits <= 0) {
                    return null;
                }

                $totalDelta = round(-1 * $item['final_unit_price'] * $freeUnits, 2);

                return [
                    'quantity' => (float) $freeUnits,
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'buy_x_pay_y',
                        'buy_quantity' => $buyQuantity,
                        'pay_quantity' => $payQuantity,
                    ],
                ];
            },
            'Promocion compra X paga Y %s',
        );
    }

    private function applyBuyXGetYFree(Collection $items, array $promotion, array $rule): ?array
    {
        $buyQuantity = max((int) ($rule['config']['buy_quantity'] ?? 0), 0);
        $freeQuantity = max((int) ($rule['config']['free_quantity'] ?? 0), 0);

        if ($buyQuantity <= 0 || $freeQuantity <= 0) {
            return null;
        }

        $bundleSize = $buyQuantity + $freeQuantity;

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($buyQuantity, $freeQuantity, $bundleSize) {
                $groups = intdiv($item['requested_quantity'], $bundleSize);
                $appliedFreeUnits = $groups * $freeQuantity;

                if ($appliedFreeUnits <= 0) {
                    return null;
                }

                $totalDelta = round(-1 * $item['final_unit_price'] * $appliedFreeUnits, 2);

                return [
                    'quantity' => (float) $appliedFreeUnits,
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'buy_x_get_y_free',
                        'buy_quantity' => $buyQuantity,
                        'free_quantity' => $freeQuantity,
                    ],
                ];
            },
            'Promocion compra X lleva Y gratis %s',
        );
    }

    private function applySecondUnitPercentOff(Collection $items, array $promotion, array $rule): ?array
    {
        $percent = round((float) ($rule['config']['percent_off'] ?? 0), 2);

        if ($percent <= 0) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($percent) {
                $discountedUnits = intdiv($item['requested_quantity'], 2);

                if ($discountedUnits <= 0) {
                    return null;
                }

                $totalDelta = round(-1 * $item['final_unit_price'] * ($percent / 100) * $discountedUnits, 2);

                return [
                    'quantity' => (float) $discountedUnits,
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'second_unit_percent_off',
                        'percent_off' => $percent,
                    ],
                ];
            },
            'Segunda unidad con descuento %s',
        );
    }

    private function applyTieredQuantityPrice(Collection $items, array $promotion, array $rule): ?array
    {
        $tiers = collect($rule['config']['tiers'] ?? [])
            ->filter(fn (array $tier) => isset($tier['min_quantity'], $tier['unit_price']))
            ->sortByDesc('min_quantity')
            ->values();

        if ($tiers->isEmpty()) {
            return null;
        }

        return $this->applyPerItemRule(
            $items,
            $promotion,
            $rule,
            function (array $item) use ($tiers) {
                $tier = $tiers->first(fn (array $candidate) => $item['requested_quantity'] >= (int) $candidate['min_quantity']);

                if ($tier === null) {
                    return null;
                }

                $targetUnitPrice = round((float) $tier['unit_price'], 2);

                if ($targetUnitPrice >= $item['final_unit_price']) {
                    return null;
                }

                $totalDelta = round(($targetUnitPrice - $item['final_unit_price']) * $item['requested_quantity'], 2);

                return [
                    'quantity' => (float) $item['requested_quantity'],
                    'total_delta' => $totalDelta,
                    'metadata' => [
                        'rule_type' => 'tiered_quantity_price',
                        'matched_tier' => $tier,
                    ],
                ];
            },
            'Precio escalonado por cantidad %s',
        );
    }

    private function applyCartAmountPercentOff(Collection $items, array $promotion, array $rule): ?array
    {
        $percent = round((float) ($rule['config']['percent_off'] ?? 0), 2);
        $minCartAmount = round((float) ($rule['config']['min_cart_amount'] ?? 0), 2);
        $matchedKeys = collect($rule['matched_item_keys'] ?? [])->unique()->values();

        if ($percent <= 0 || $matchedKeys->isEmpty()) {
            return null;
        }

        $cartSubtotal = round($items->sum(fn (array $item) => $item['final_unit_price'] * $item['requested_quantity']), 2);

        if ($cartSubtotal < $minCartAmount) {
            return null;
        }

        $discountAmount = 0.0;
        $appliedRuleCount = 0;

        foreach ($matchedKeys as $itemKey) {
            $item = $items->get($itemKey);

            if ($item === null) {
                continue;
            }

            $currentLineTotal = round($item['final_unit_price'] * $item['requested_quantity'], 2);
            $totalDelta = round(-1 * $currentLineTotal * ($percent / 100), 2);

            if ($totalDelta >= 0) {
                continue;
            }

            $this->applyAdjustment(
                $items,
                (int) $itemKey,
                $promotion,
                $rule,
                'Descuento por monto de carrito %s',
                (float) $item['requested_quantity'],
                $totalDelta,
                [
                    'rule_type' => 'cart_amount_percent_off',
                    'percent_off' => $percent,
                    'min_cart_amount' => $minCartAmount,
                ],
            );

            $discountAmount += abs($totalDelta);
            $appliedRuleCount++;
        }

        if ($appliedRuleCount === 0) {
            return null;
        }

        return [
            'discount_amount' => round($discountAmount, 2),
            'applied_rule_count' => $appliedRuleCount,
        ];
    }

    private function applyPerItemRule(
        Collection $items,
        array $promotion,
        array $rule,
        callable $calculator,
        string $descriptionTemplate
    ): ?array {
        $discountAmount = 0.0;
        $appliedRuleCount = 0;

        foreach (collect($rule['matched_item_keys'] ?? [])->unique()->values() as $itemKey) {
            $item = $items->get($itemKey);

            if ($item === null) {
                continue;
            }

            $calculated = $calculator($item);

            if ($calculated === null) {
                continue;
            }

            $totalDelta = round((float) $calculated['total_delta'], 2);

            if ($totalDelta >= 0) {
                continue;
            }

            $this->applyAdjustment(
                $items,
                (int) $itemKey,
                $promotion,
                $rule,
                $descriptionTemplate,
                (float) $calculated['quantity'],
                $totalDelta,
                $calculated['metadata'],
            );

            $discountAmount += abs($totalDelta);
            $appliedRuleCount++;
        }

        if ($appliedRuleCount === 0) {
            return null;
        }

        return [
            'discount_amount' => round($discountAmount, 2),
            'applied_rule_count' => $appliedRuleCount,
        ];
    }

    private function applyAdjustment(
        Collection $items,
        int $itemKey,
        array $promotion,
        array $rule,
        string $descriptionTemplate,
        float $quantity,
        float $totalDelta,
        array $metadata
    ): void {
        $item = $items->get($itemKey);
        $baseLineTotal = round($item['base_unit_price'] * $item['requested_quantity'], 2);
        $nextDiscountAmount = min(round($item['line_discount_amount'] + abs($totalDelta), 2), $baseLineTotal);
        $nextLineTotal = max(round($baseLineTotal - $nextDiscountAmount, 2), 0);

        $item['line_discount_amount'] = $nextDiscountAmount;
        $item['line_total'] = $nextLineTotal;
        $item['final_unit_price'] = $item['requested_quantity'] > 0
            ? round($nextLineTotal / $item['requested_quantity'], 2)
            : 0.0;
        $item['adjustments'][] = [
            'adjustment_type' => 'promotion_discount',
            'description' => sprintf($descriptionTemplate, $promotion['code']),
            'promotion_id' => $promotion['id'],
            'promotion_rule_id' => $rule['id'],
            'sponsor_supplier_id' => $promotion['sponsor_supplier']['id'] ?? null,
            'quantity' => round($quantity, 2),
            'unit_delta' => $quantity > 0 ? round($totalDelta / $quantity, 2) : 0.0,
            'total_delta' => round($totalDelta, 2),
            'metadata' => array_merge($metadata, [
                'promotion_code' => $promotion['code'],
                'promotion_name' => $promotion['name'],
                'sponsor_supplier' => $promotion['sponsor_supplier'],
            ]),
        ];

        $items->put($itemKey, $item);
    }
}
