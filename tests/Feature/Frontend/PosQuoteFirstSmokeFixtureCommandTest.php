<?php

namespace Tests\Feature\Frontend;

use App\Services\Pricing\PricingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosQuoteFirstSmokeFixtureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_seeds_idempotent_pos_quote_first_smoke_fixture(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
            \Database\Seeders\InventoryCatalogSeeder::class,
        ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $userId = (int) DB::table('users')->where('email', 'pos-smoke@velmix.test')->value('id');
        $productId = (int) DB::table('products')
            ->where('tenant_id', 10)
            ->where('sku', 'SMOKE-POS-REG-001')
            ->value('id');
        $legacyProductId = (int) DB::table('products')
            ->where('tenant_id', 10)
            ->where('sku', 'PARA-500')
            ->value('id');
        $priceListId = (int) DB::table('price_lists')
            ->where('tenant_id', 10)
            ->where('channel', 'retail')
            ->where('is_default', true)
            ->value('id');

        $this->assertGreaterThan(0, $userId);
        $this->assertGreaterThan(0, $productId);
        $this->assertGreaterThan(0, $legacyProductId);
        $this->assertGreaterThan(0, $priceListId);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => 10,
            'user_id' => $userId,
        ]);
        $this->assertDatabaseHas('lots', [
            'tenant_id' => 10,
            'product_id' => $productId,
            'code' => 'LOT-SMOKE-POS-REG-001',
            'status' => 'available',
        ]);
        $this->assertSame(1, DB::table('promotions')->where('tenant_id', 10)->where('code', 'SMOKE-PROMO10')->count());
        $promotionId = (int) DB::table('promotions')->where('tenant_id', 10)->where('code', 'SMOKE-PROMO10')->value('id');
        $this->assertSame(1, DB::table('promotion_rules')->where('promotion_id', $promotionId)->count());
        $this->assertSame(1, DB::table('promotion_targets')->where('promotion_id', $promotionId)->count());
        $this->assertSame(1, DB::table('promotion_audiences')->where('promotion_id', $promotionId)->count());
        $this->assertSame(1, DB::table('cash_sessions')->where('tenant_id', 10)->where('status', 'open')->count());
        $this->assertDatabaseHas('price_list_items', [
            'price_list_id' => $priceListId,
            'product_id' => $legacyProductId,
            'status' => 'active',
        ]);

        DB::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('product_id', $legacyProductId)
            ->update([
                'valid_from' => now()->addDay(),
                'updated_at' => now(),
            ]);

        $this->artisan('frontend:seed-pos-smoke', [
            '--json' => true,
        ])->assertExitCode(0);

        $legacyPriceValidFrom = DB::table('price_list_items')
            ->where('price_list_id', $priceListId)
            ->where('product_id', $legacyProductId)
            ->value('valid_from');

        $this->assertNotNull($legacyPriceValidFrom);
        $this->assertLessThanOrEqual(now()->getTimestamp(), strtotime((string) $legacyPriceValidFrom));

        $quote = app(PricingQuoteService::class)->create(
            10,
            $userId,
            [
                [
                    'product_id' => $productId,
                    'quantity' => 1,
                ],
            ],
            'card',
        );

        $this->assertSame('quoted', $quote['status']);
        $this->assertSame(12.0, $quote['summary']['subtotal_amount']);
        $this->assertSame(1.2, $quote['summary']['discount_amount']);
        $this->assertSame(10.8, $quote['summary']['total_amount']);
        $this->assertSame('SMOKE-PROMO10', $quote['applied_promotions'][0]['code']);

        $legacyQuote = app(PricingQuoteService::class)->create(
            10,
            $userId,
            [
                [
                    'product_id' => $legacyProductId,
                    'quantity' => 1,
                ],
            ],
            'card',
        );

        $this->assertSame('quoted', $legacyQuote['status']);
        $this->assertSame('PARA-500', $legacyQuote['items'][0]['product_sku']);
        $this->assertGreaterThan(0, $legacyQuote['summary']['total_amount']);
    }
}
