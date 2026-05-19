<?php

namespace Tests\Feature\Pricing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingQuoteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_and_read_pricing_quote_over_http(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $laboratoryId = $this->createSupplier(10, 'laboratory', 'LAB-HTTP', 'Laboratorio HTTP');
        $productId = $this->createProduct(10, 'PROMO-HTTP', $laboratoryId);
        $priceListId = $this->createPriceList(10, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 10.00);

        $promotionId = $this->createPromotion(10, [
            'code' => 'LAB-HTTP-10',
            'stack_mode' => 'best_price_only',
            'sponsor_supplier_id' => $laboratoryId,
        ]);
        $this->createPromotionTarget($promotionId, 'laboratory', $laboratoryId);
        $this->createPromotionAudience($promotionId, 'all');
        $this->createPromotionRule($promotionId, 'percent_off', ['percent_off' => 10]);

        $createResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-http-test')
            ->postJson('/pricing/quotes', [
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $createResponse->assertOk()
            ->assertJsonPath('data.price_list.id', $priceListId)
            ->assertJsonPath('data.summary.subtotal_amount', 20)
            ->assertJsonPath('data.summary.discount_amount', 2)
            ->assertJsonPath('data.summary.total_amount', 18)
            ->assertJsonPath('data.applied_promotions.0.code', 'LAB-HTTP-10');

        $quoteId = (int) $createResponse->json('data.id');

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->getJson("/pricing/quotes/{$quoteId}")
            ->assertOk()
            ->assertJsonPath('data.id', $quoteId)
            ->assertJsonPath('data.items.0.product_id', $productId)
            ->assertJsonPath('data.items.0.adjustments.1.type', 'promotion_discount')
            ->assertJsonPath('data.applied_promotions.0.code', 'LAB-HTTP-10');
    }

    public function test_warehouse_role_cannot_create_pricing_quote_over_http(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $warehouse = $this->seedUserWithRole(10, 'ALMACENERO');
        $productId = $this->createProduct(10, 'FORBID-QUOTE');
        $priceListId = $this->createPriceList(10, [
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 5.00);

        $this->actingAs($warehouse)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-http-forbidden')
            ->postJson('/pricing/quotes', [
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    private function seedUserWithRole(int $tenantId, string $roleCode): User
    {
        $user = User::factory()->create();
        $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('tenant_user')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_user_role')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
