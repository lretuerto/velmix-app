<?php

namespace Tests\Feature\Pricing;

use App\Models\User;
use App\Services\Pricing\PricingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricingQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_quote_with_persisted_quote_items_and_base_price_adjustments(): void
    {
        $tenantId = $this->createTenant();
        $user = User::factory()->create();
        $this->attachUserToTenant($tenantId, $user->id);
        $customerId = $this->createCustomer($tenantId);
        $productAId = $this->createProduct($tenantId, 'PARA-500');
        $productBId = $this->createProduct($tenantId, 'AMOX-500');
        $priceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productAId, 8.00);
        $this->createPriceListItem($priceListId, $productBId, 12.50);

        $quote = app(PricingQuoteService::class)->create(
            $tenantId,
            $user->id,
            [
                ['product_id' => $productAId, 'quantity' => 2],
                ['product_id' => $productBId, 'quantity' => 1],
            ],
            'cash',
            $customerId,
            'retail',
        );

        $this->assertSame('quoted', $quote['status']);
        $this->assertSame($priceListId, $quote['price_list']['id']);
        $this->assertSame(28.5, $quote['summary']['subtotal_amount']);
        $this->assertSame(0.0, $quote['summary']['discount_amount']);
        $this->assertSame(28.5, $quote['summary']['total_amount']);
        $this->assertCount(2, $quote['items']);
        $this->assertStringStartsWith('sha256:', $quote['quote_hash']);

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quote['id'],
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'price_list_id' => $priceListId,
            'payment_method' => 'cash',
            'status' => 'quoted',
        ]);

        $quoteItemIds = DB::table('pricing_quote_items')
            ->where('pricing_quote_id', $quote['id'])
            ->pluck('id');

        $this->assertCount(2, $quoteItemIds);
        $this->assertSame(2, DB::table('pricing_quote_adjustments')->whereIn('pricing_quote_item_id', $quoteItemIds)->count());
    }

    public function test_it_applies_promotions_to_quote_and_persists_promotion_adjustments(): void
    {
        $tenantId = $this->createTenant();
        $user = User::factory()->create();
        $this->attachUserToTenant($tenantId, $user->id);
        $laboratoryId = $this->createSupplier($tenantId, 'laboratory', 'LAB-QUOTE', 'Laboratorio Quote');
        $productId = $this->createProduct($tenantId, 'PROMO-ITEM', $laboratoryId);
        $priceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 10.00);

        $promotionId = $this->createPromotion($tenantId, [
            'code' => 'LAB-10',
            'stack_mode' => 'best_price_only',
            'sponsor_supplier_id' => $laboratoryId,
        ]);
        $this->createPromotionTarget($promotionId, 'laboratory', $laboratoryId);
        $this->createPromotionAudience($promotionId, 'all');
        $this->createPromotionRule($promotionId, 'percent_off', ['percent_off' => 10]);

        $quote = app(PricingQuoteService::class)->create(
            $tenantId,
            $user->id,
            [['product_id' => $productId, 'quantity' => 2]],
            'cash',
            null,
            'retail',
        );

        $this->assertSame(20.0, $quote['summary']['subtotal_amount']);
        $this->assertSame(2.0, $quote['summary']['discount_amount']);
        $this->assertSame(18.0, $quote['summary']['total_amount']);
        $this->assertSame('LAB-10', $quote['applied_promotions'][0]['code']);
        $this->assertCount(2, $quote['items'][0]['adjustments']);

        $quoteItemId = (int) DB::table('pricing_quote_items')
            ->where('pricing_quote_id', $quote['id'])
            ->value('id');

        $this->assertDatabaseHas('pricing_quote_adjustments', [
            'pricing_quote_item_id' => $quoteItemId,
            'promotion_id' => $promotionId,
            'adjustment_type' => 'promotion_discount',
            'sponsor_supplier_id' => $laboratoryId,
        ]);
    }

    public function test_it_rejects_quote_creation_when_user_is_not_attached_to_tenant(): void
    {
        $tenantId = $this->createTenant();
        $user = User::factory()->create();
        $productId = $this->createProduct($tenantId, 'IBU-400');
        $priceListId = $this->createPriceList($tenantId, [
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 6.90);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Authenticated user is not attached to tenant.');

        app(PricingQuoteService::class)->create(
            $tenantId,
            $user->id,
            [['product_id' => $productId, 'quantity' => 1]],
            'cash',
            null,
            'retail',
        );
    }

    private function createTenant(): int
    {
        return DB::table('tenants')->insertGetId([
            'code' => 'tenant-quote',
            'name' => 'Tenant Quote',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachUserToTenant(int $tenantId, int $userId): void
    {
        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCustomer(int $tenantId): int
    {
        return DB::table('customers')->insertGetId([
            'tenant_id' => $tenantId,
            'document_type' => 'RUC',
            'document_number' => '20100000002',
            'name' => 'Botica Demo',
            'status' => 'active',
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
