<?php

namespace Tests\Feature\Pricing;

use App\Models\User;
use App\Services\Pricing\PricingCheckoutService;
use App\Services\Pricing\PricingQuoteService;
use App\Services\Sales\PosSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PricingCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consumes_a_quote_and_persists_sale_item_pricing_components(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $tenantId = 10;
        $user = User::factory()->create();
        $this->attachUserToTenant($tenantId, $user->id);

        $laboratoryId = $this->createSupplier($tenantId, 'laboratory', 'LAB-CHECKOUT', 'Laboratorio Checkout');
        $productId = $this->createProduct($tenantId, 'PROMO-CHECKOUT', $laboratoryId, 4.00);
        $this->createLot($tenantId, $productId, 'LOT-CHECKOUT-001', 10);
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

        $cashSessionId = (int) DB::table('cash_sessions')->insertGetId([
            'tenant_id' => $tenantId,
            'opened_by_user_id' => $user->id,
            'closed_by_user_id' => null,
            'opening_amount' => 100,
            'expected_amount' => 100,
            'counted_amount' => null,
            'discrepancy_amount' => null,
            'status' => 'open',
            'open_guard' => 'tenant:'.$tenantId,
            'opened_at' => now(),
            'closed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PricingCheckoutService::class)->execute(
            $tenantId,
            $user->id,
            (int) $quote['id'],
            (string) $quote['quote_hash'],
        );

        $saleId = (int) $result['sale']['sale_id'];

        $this->assertSame('consumed', $result['quote']['status']);
        $this->assertSame($saleId, $result['quote']['sale_id']);
        $this->assertSame(18.0, $result['sale']['total_amount']);

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quote['id'],
            'status' => 'consumed',
            'sale_id' => $saleId,
        ]);

        $saleItemId = (int) DB::table('sale_items')->where('sale_id', $saleId)->value('id');

        $this->assertDatabaseHas('sale_item_pricing_components', [
            'sale_item_id' => $saleItemId,
            'pricing_quote_item_id' => $quote['items'][0]['id'],
            'component_type' => 'base_price',
            'promotion_id' => null,
            'unit_amount' => 10.00,
            'total_amount' => 20.00,
        ]);

        $this->assertDatabaseHas('sale_item_pricing_components', [
            'sale_item_id' => $saleItemId,
            'pricing_quote_item_id' => $quote['items'][0]['id'],
            'component_type' => 'promotion_discount',
            'promotion_id' => $promotionId,
            'sponsor_supplier_id' => $laboratoryId,
            'unit_amount' => -1.00,
            'total_amount' => -2.00,
        ]);

        $this->assertDatabaseHas('lots', [
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'code' => 'LOT-CHECKOUT-001',
            'stock_quantity' => 8,
        ]);

        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => $tenantId,
            'cash_session_id' => $cashSessionId,
            'source_type' => 'sale',
            'source_id' => $saleId,
            'entry_type' => 'sale_cash_in',
            'direction' => 'in',
            'amount' => 18.00,
        ]);
    }

    public function test_checkout_rejects_quote_header_total_drift_before_creating_sale(): void
    {
        [$tenantId, $user, $quote] = $this->seedSimpleCheckoutQuote('PROMO-CHECKOUT-DRIFT');

        DB::table('pricing_quotes')
            ->where('id', $quote['id'])
            ->update([
                'total_amount' => 999.00,
                'updated_at' => now(),
            ]);

        try {
            app(PricingCheckoutService::class)->execute(
                $tenantId,
                $user->id,
                (int) $quote['id'],
                (string) $quote['quote_hash'],
            );

            $this->fail('Checkout should reject a quote whose header totals drift from its persisted items.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Pricing quote totals do not match persisted quote items.', $exception->getMessage());
        }

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quote['id'],
            'status' => 'quoted',
            'sale_id' => null,
        ]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_checkout_rejects_downstream_sale_total_drift_without_consuming_quote(): void
    {
        [$tenantId, $user, $quote] = $this->seedSimpleCheckoutQuote('PROMO-CHECKOUT-SALE-DRIFT');

        $service = new PricingCheckoutService(new class extends PosSaleService
        {
            public function execute(
                int $tenantId,
                int $userId,
                array $items,
                string $paymentMethod = 'cash',
                ?int $customerId = null,
                ?string $dueAt = null
            ): array {
                return [
                    'sale_id' => 9001,
                    'reference' => 'SALE-FAKE-9001',
                    'payment_method' => $paymentMethod,
                    'customer' => null,
                    'total_amount' => 999.00,
                    'gross_cost' => 0.00,
                    'gross_margin' => 999.00,
                    'items' => [],
                ];
            }
        });

        try {
            $service->execute(
                $tenantId,
                $user->id,
                (int) $quote['id'],
                (string) $quote['quote_hash'],
            );

            $this->fail('Checkout should reject downstream sale total drift.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Checkout sale result does not match the pricing quote snapshot.', $exception->getMessage());
        }

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quote['id'],
            'status' => 'quoted',
            'sale_id' => null,
        ]);
        $this->assertDatabaseCount('sale_item_pricing_components', 0);
    }

    public function test_checkout_rejects_downstream_sale_items_without_quote_mapping(): void
    {
        [$tenantId, $user, $quote] = $this->seedSimpleCheckoutQuote('PROMO-CHECKOUT-MISSING-MAP');

        $service = new PricingCheckoutService(new class($quote) extends PosSaleService
        {
            public function __construct(private readonly array $quote) {}

            public function execute(
                int $tenantId,
                int $userId,
                array $items,
                string $paymentMethod = 'cash',
                ?int $customerId = null,
                ?string $dueAt = null
            ): array {
                return [
                    'sale_id' => 9002,
                    'reference' => 'SALE-FAKE-9002',
                    'payment_method' => $paymentMethod,
                    'customer' => null,
                    'total_amount' => $this->quote['summary']['total_amount'],
                    'gross_cost' => 0.00,
                    'gross_margin' => $this->quote['summary']['total_amount'],
                    'items' => [[
                        'product_id' => $items[0]['product_id'],
                        'quantity' => $items[0]['quantity'],
                        'unit_price' => $items[0]['unit_price'],
                        'line_total' => $items[0]['line_total'],
                        'allocations' => [[
                            'sale_item_id' => 9002,
                            'quantity' => $items[0]['quantity'],
                        ]],
                    ]],
                ];
            }
        });

        try {
            $service->execute(
                $tenantId,
                $user->id,
                (int) $quote['id'],
                (string) $quote['quote_hash'],
            );

            $this->fail('Checkout should reject sale items without quote mapping.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Checkout sale item is missing quote item mapping.', $exception->getMessage());
        }

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quote['id'],
            'status' => 'quoted',
            'sale_id' => null,
        ]);
        $this->assertDatabaseCount('sale_item_pricing_components', 0);
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

    private function seedSimpleCheckoutQuote(string $sku): array
    {
        $this->seed(\Database\Seeders\TenantSeeder::class);

        $tenantId = 10;
        $user = User::factory()->create();
        $this->attachUserToTenant($tenantId, $user->id);

        $productId = $this->createProduct($tenantId, $sku, null, 4.00);
        $priceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-'.$sku,
            'is_default' => true,
        ]);
        $this->createPriceListItem($priceListId, $productId, 10.00);

        $quote = app(PricingQuoteService::class)->create(
            $tenantId,
            $user->id,
            [['product_id' => $productId, 'quantity' => 2]],
            'cash',
            null,
            'retail',
        );

        return [$tenantId, $user, $quote];
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

    private function createProduct(int $tenantId, string $sku, ?int $laboratoryId = null, float $averageCost = 0): int
    {
        return DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => 'Producto '.$sku,
            'status' => 'active',
            'commercial_status' => 'active',
            'laboratory_supplier_id' => $laboratoryId,
            'is_controlled' => false,
            'average_cost' => $averageCost,
            'last_cost' => $averageCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLot(int $tenantId, int $productId, string $code, int $stockQuantity): int
    {
        return DB::table('lots')->insertGetId([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'code' => $code,
            'expires_at' => now()->addYear()->toDateString(),
            'stock_quantity' => $stockQuantity,
            'status' => 'available',
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
