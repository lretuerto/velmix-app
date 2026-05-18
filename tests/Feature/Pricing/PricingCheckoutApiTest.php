<?php

namespace Tests\Feature\Pricing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_checkout_quote_over_http(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $productId = $this->createProduct(10, 'POS-CHECKOUT-HTTP', false, 3.50);
        $this->createLot(10, $productId, 'LOT-POS-HTTP-001', 12);
        $priceListId = $this->createPriceList(10, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 9.90);

        $quoteResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-checkout-http-create')
            ->postJson('/pricing/quotes', [
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $quoteResponse->assertOk();
        $quoteId = (int) $quoteResponse->json('data.id');
        $quoteHash = (string) $quoteResponse->json('data.quote_hash');
        $cashSessionId = $this->openCashSession($cashier);

        $checkoutResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-checkout-http-confirm')
            ->postJson("/pricing/quotes/{$quoteId}/checkout", [
                'quote_hash' => $quoteHash,
            ]);

        $checkoutResponse->assertOk()
            ->assertJsonPath('data.quote.id', $quoteId)
            ->assertJsonPath('data.quote.status', 'consumed')
            ->assertJsonPath('data.sale.payment_method', 'cash')
            ->assertJsonPath('data.sale.total_amount', 19.8);

        $saleId = (int) $checkoutResponse->json('data.sale.sale_id');

        $this->assertDatabaseHas('pricing_quotes', [
            'id' => $quoteId,
            'status' => 'consumed',
            'sale_id' => $saleId,
        ]);

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'cash_session_id' => $cashSessionId,
        ]);

        $this->assertDatabaseHas('cash_session_ledger_entries', [
            'tenant_id' => 10,
            'cash_session_id' => $cashSessionId,
            'source_type' => 'sale',
            'source_id' => $saleId,
            'entry_type' => 'sale_cash_in',
            'direction' => 'in',
            'amount' => 19.80,
        ]);
    }

    public function test_controlled_product_checkout_accepts_prescription_input(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $productId = $this->createProduct(10, 'CLON-CHECKOUT-HTTP', true, 4.00);
        $this->createLot(10, $productId, 'LOT-CTRL-HTTP-001', 6);
        $priceListId = $this->createPriceList(10, [
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 12.00);

        $quoteResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-controlled-http-create')
            ->postJson('/pricing/quotes', [
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $quoteResponse->assertOk();
        $this->openCashSession($cashier);

        $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-controlled-http-checkout')
            ->postJson('/pricing/quotes/'.$quoteResponse->json('data.id').'/checkout', [
                'quote_hash' => $quoteResponse->json('data.quote_hash'),
                'line_inputs' => [
                    [
                        'quote_item_id' => $quoteResponse->json('data.items.0.id'),
                        'prescription_code' => 'RX-HTTP-001',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.sale.items.0.prescription_code', 'RX-HTTP-001');

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $productId,
            'prescription_code' => 'RX-HTTP-001',
        ]);
    }

    public function test_checkout_replay_with_same_idempotency_key_does_not_duplicate_sale_or_cash_ledger(): void
    {
        $this->seed([
            \Database\Seeders\TenantSeeder::class,
            \Database\Seeders\RbacCatalogSeeder::class,
        ]);

        $cashier = $this->seedUserWithRole(10, 'CAJERO');
        $productId = $this->createProduct(10, 'POS-CHECKOUT-REPLAY', false, 3.50);
        $this->createLot(10, $productId, 'LOT-POS-REPLAY-001', 12);
        $priceListId = $this->createPriceList(10, [
            'code' => 'RETAIL-REPLAY',
            'is_default' => true,
        ]);

        $this->createPriceListItem($priceListId, $productId, 8.25);

        $quoteResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-checkout-replay-create')
            ->postJson('/pricing/quotes', [
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productId,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $quoteResponse->assertOk();
        $quoteId = (int) $quoteResponse->json('data.id');
        $quoteHash = (string) $quoteResponse->json('data.quote_hash');
        $cashSessionId = $this->openCashSession($cashier);

        $firstResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-checkout-replay-confirm')
            ->postJson("/pricing/quotes/{$quoteId}/checkout", [
                'quote_hash' => $quoteHash,
            ]);

        $firstResponse->assertOk();
        $saleId = (int) $firstResponse->json('data.sale.sale_id');

        $secondResponse = $this->actingAs($cashier)
            ->withHeader('X-Tenant-Id', '10')
            ->withHeader('Idempotency-Key', 'quote-checkout-replay-confirm')
            ->postJson("/pricing/quotes/{$quoteId}/checkout", [
                'quote_hash' => $quoteHash,
            ]);

        $secondResponse->assertOk()
            ->assertJsonPath('data.quote.status', 'consumed')
            ->assertJsonPath('data.sale.sale_id', $saleId)
            ->assertJsonPath('data.sale.total_amount', 16.5);

        $this->assertSame(1, DB::table('sales')->where('tenant_id', 10)->count());
        $this->assertSame(1, DB::table('cash_session_ledger_entries')
            ->where('tenant_id', 10)
            ->where('cash_session_id', $cashSessionId)
            ->where('source_type', 'sale')
            ->where('source_id', $saleId)
            ->count());
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

    private function openCashSession(User $user, int $tenantId = 10, float $openingAmount = 1000): int
    {
        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', (string) $tenantId)
            ->postJson('/cash/sessions/open', [
                'opening_amount' => $openingAmount,
            ])
            ->assertOk();

        return (int) DB::table('cash_sessions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->value('id');
    }

    private function createProduct(int $tenantId, string $sku, bool $isControlled, float $averageCost): int
    {
        return DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => 'Producto '.$sku,
            'status' => 'active',
            'commercial_status' => 'active',
            'is_controlled' => $isControlled,
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
}
