<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PriceListResolverService;
use App\Services\Pricing\PromotionEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_chooses_the_best_best_price_only_promotion_for_a_line(): void
    {
        $tenantId = $this->createTenant();
        $productId = $this->createProduct($tenantId, 'PARA-500');
        $priceListId = $this->createPriceList($tenantId, ['is_default' => true]);
        $this->createPriceListItem($priceListId, $productId, 10.00);

        $percentPromoId = $this->createPromotion($tenantId, [
            'code' => 'PCT-10',
            'priority' => 100,
            'stack_mode' => 'best_price_only',
        ]);
        $this->createPromotionTarget($percentPromoId, 'product', $productId);
        $this->createPromotionAudience($percentPromoId, 'all');
        $this->createPromotionRule($percentPromoId, 'percent_off', ['percent_off' => 10]);

        $fixedPromoId = $this->createPromotion($tenantId, [
            'code' => 'FIX-7',
            'priority' => 100,
            'stack_mode' => 'best_price_only',
        ]);
        $this->createPromotionTarget($fixedPromoId, 'product', $productId);
        $this->createPromotionAudience($fixedPromoId, 'all');
        $this->createPromotionRule($fixedPromoId, 'fixed_unit_price', ['unit_price' => 7.00]);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 2]],
            null,
            'retail',
        );

        $priced = app(PromotionEngineService::class)->apply($tenantId, $resolved, 'cash');

        $this->assertSame(6.0, $priced['items'][0]['line_discount_amount']);
        $this->assertSame(14.0, $priced['items'][0]['line_total']);
        $this->assertSame('FIX-7', $priced['applied_promotions'][0]['code']);
        $this->assertCount(2, $priced['items'][0]['adjustments']);
    }

    public function test_it_applies_stackable_laboratory_promotions_in_sequence(): void
    {
        $tenantId = $this->createTenant();
        $laboratoryId = $this->createSupplier($tenantId, 'laboratory', 'LAB-STACK', 'Laboratorio Stack');
        $productId = $this->createProduct($tenantId, 'IBU-400', $laboratoryId);
        $priceListId = $this->createPriceList($tenantId, ['is_default' => true]);
        $this->createPriceListItem($priceListId, $productId, 10.00);

        $percentPromoId = $this->createPromotion($tenantId, [
            'code' => 'LAB-PCT',
            'priority' => 100,
            'stack_mode' => 'stackable',
            'sponsor_supplier_id' => $laboratoryId,
        ]);
        $this->createPromotionTarget($percentPromoId, 'laboratory', $laboratoryId);
        $this->createPromotionAudience($percentPromoId, 'all');
        $this->createPromotionRule($percentPromoId, 'percent_off', ['percent_off' => 10]);

        $secondUnitPromoId = $this->createPromotion($tenantId, [
            'code' => 'LAB-2DA-50',
            'priority' => 100,
            'stack_mode' => 'stackable',
            'sponsor_supplier_id' => $laboratoryId,
        ]);
        $this->createPromotionTarget($secondUnitPromoId, 'laboratory', $laboratoryId);
        $this->createPromotionAudience($secondUnitPromoId, 'all');
        $this->createPromotionRule($secondUnitPromoId, 'second_unit_percent_off', ['percent_off' => 50]);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 2]],
            null,
            'retail',
        );

        $priced = app(PromotionEngineService::class)->apply($tenantId, $resolved, 'cash');

        $this->assertSame(13.5, $priced['items'][0]['line_total']);
        $this->assertSame(6.5, $priced['items'][0]['line_discount_amount']);
        $this->assertSame(['LAB-PCT', 'LAB-2DA-50'], collect($priced['applied_promotions'])->pluck('code')->all());
        $this->assertCount(3, $priced['items'][0]['adjustments']);
        $this->assertSame($laboratoryId, $priced['items'][0]['adjustments'][1]['sponsor_supplier_id']);
        $this->assertSame($laboratoryId, $priced['items'][0]['adjustments'][2]['sponsor_supplier_id']);
    }

    private function createTenant(): int
    {
        return DB::table('tenants')->insertGetId([
            'code' => 'tenant-promo-engine',
            'name' => 'Tenant Promo Engine',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSupplier(int $tenantId, string $kind, string $commercialCode, string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'tenant_id' => $tenantId,
            'tax_id' => fake()->numerify('20#########'),
            'name' => $name,
            'status' => 'active',
            'kind' => $kind,
            'commercial_code' => $commercialCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProduct(int $tenantId, string $sku, ?int $laboratoryId = null): int
    {
        return DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => 'Producto '.$sku,
            'status' => 'active',
            'commercial_status' => 'active',
            'laboratory_supplier_id' => $laboratoryId,
            'is_controlled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPriceList(int $tenantId, array $overrides = []): int
    {
        return DB::table('price_lists')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'code' => 'PL-'.strtoupper(fake()->bothify('??##')),
            'name' => 'Lista '.fake()->word(),
            'status' => 'active',
            'channel' => 'retail',
            'currency' => 'PEN',
            'is_default' => false,
            'priority' => 100,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createPriceListItem(int $priceListId, int $productId, float $unitPrice): int
    {
        return DB::table('price_list_items')->insertGetId([
            'price_list_id' => $priceListId,
            'product_id' => $productId,
            'unit_price' => $unitPrice,
            'min_unit_price' => null,
            'max_discount_pct' => null,
            'valid_from' => now()->subDay(),
            'valid_until' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPromotion(int $tenantId, array $overrides = []): int
    {
        return DB::table('promotions')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'code' => 'PROMO-'.strtoupper(fake()->bothify('??##')),
            'name' => 'Promo '.fake()->word(),
            'description' => null,
            'status' => 'active',
            'sponsor_supplier_id' => null,
            'channel' => 'retail',
            'priority' => 100,
            'stack_mode' => 'best_price_only',
            'stop_further_processing' => false,
            'requires_customer' => false,
            'allowed_payment_methods' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'budget_cap' => null,
            'budget_used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createPromotionTarget(int $promotionId, string $targetType, ?int $targetId, bool $exclude = false): int
    {
        return DB::table('promotion_targets')->insertGetId([
            'promotion_id' => $promotionId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'exclude' => $exclude,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPromotionAudience(int $promotionId, string $audienceType, ?int $audienceId = null): int
    {
        return DB::table('promotion_audiences')->insertGetId([
            'promotion_id' => $promotionId,
            'audience_type' => $audienceType,
            'audience_id' => $audienceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPromotionRule(int $promotionId, string $ruleType, array $config): int
    {
        return DB::table('promotion_rules')->insertGetId([
            'promotion_id' => $promotionId,
            'rule_type' => $ruleType,
            'scope' => 'line',
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'priority' => 100,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
