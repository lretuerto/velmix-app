<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PriceListResolverService;
use App\Services\Pricing\PromotionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_product_and_price_list_promotions_when_target_and_audience_match(): void
    {
        $tenantId = $this->createTenant();
        $customerId = $this->createCustomer($tenantId);
        $laboratoryId = $this->createSupplier($tenantId, 'laboratory', 'LAB-001', 'Laboratorio Uno');
        $productId = $this->createProduct($tenantId, 'PARA-500', $laboratoryId);
        $priceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 10.00);

        $productPromotionId = $this->createPromotion($tenantId, [
            'code' => 'PROD-10',
            'allowed_payment_methods' => json_encode(['cash']),
        ]);
        $this->createPromotionTarget($productPromotionId, 'product', $productId);
        $this->createPromotionAudience($productPromotionId, 'all');
        $this->createPromotionRule($productPromotionId, 'percent_off', ['percent_off' => 10]);

        $priceListPromotionId = $this->createPromotion($tenantId, [
            'code' => 'LISTA-5',
        ]);
        $this->createPromotionTarget($priceListPromotionId, 'price_list', $priceListId);
        $this->createPromotionAudience($priceListPromotionId, 'customer_price_list', $priceListId);
        $this->createPromotionRule($priceListPromotionId, 'amount_off', ['amount_off' => 1.50]);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 2]],
            $customerId,
            'retail',
        );

        $eligible = app(PromotionEligibilityService::class)->eligibleForQuote($tenantId, $resolved, 'cash');

        $this->assertCount(2, $eligible);
        $this->assertSame(['PROD-10', 'LISTA-5'], collect($eligible)->pluck('code')->all());
        $this->assertSame([0], $eligible[0]['rules'][0]['matched_item_keys']);
        $this->assertSame([0], $eligible[1]['rules'][0]['matched_item_keys']);
    }

    public function test_it_filters_promotions_by_audience_payment_method_and_excluded_targets(): void
    {
        $tenantId = $this->createTenant();
        $customerId = $this->createCustomer($tenantId);
        $laboratoryId = $this->createSupplier($tenantId, 'laboratory', 'LAB-002', 'Laboratorio Dos');
        $productId = $this->createProduct($tenantId, 'AMOX-500', $laboratoryId);
        $priceListId = $this->createPriceList($tenantId, [
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 20.00);

        $walkInOnlyPromotionId = $this->createPromotion($tenantId, [
            'code' => 'MOSTRADOR',
            'allowed_payment_methods' => json_encode(['cash']),
        ]);
        $this->createPromotionTarget($walkInOnlyPromotionId, 'all_products', null);
        $this->createPromotionAudience($walkInOnlyPromotionId, 'walk_in');
        $this->createPromotionRule($walkInOnlyPromotionId, 'percent_off', ['percent_off' => 5]);

        $excludedPromotionId = $this->createPromotion($tenantId, [
            'code' => 'LAB-EXC',
            'allowed_payment_methods' => json_encode(['cash']),
        ]);
        $this->createPromotionTarget($excludedPromotionId, 'laboratory', $laboratoryId);
        $this->createPromotionTarget($excludedPromotionId, 'product', $productId, true);
        $this->createPromotionAudience($excludedPromotionId, 'all');
        $this->createPromotionRule($excludedPromotionId, 'percent_off', ['percent_off' => 25]);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 1]],
            $customerId,
            'retail',
        );

        $eligible = app(PromotionEligibilityService::class)->eligibleForQuote($tenantId, $resolved, 'card');

        $this->assertSame([], $eligible);
    }

    private function createTenant(): int
    {
        return DB::table('tenants')->insertGetId([
            'code' => 'tenant-promo-eligibility',
            'name' => 'Tenant Promo Eligibility',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCustomer(int $tenantId): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => 'RUC',
            'document_number' => '20100010001',
            'name' => 'Cliente Convenio',
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
