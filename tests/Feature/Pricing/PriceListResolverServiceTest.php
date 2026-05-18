<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PriceListResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PriceListResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_customer_assigned_price_list_before_tenant_default(): void
    {
        $tenantId = $this->createTenant();
        $customerId = $this->createCustomer($tenantId);
        $productId = $this->createProduct($tenantId, 'PARA-500');
        $defaultPriceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
            'priority' => 50,
        ]);
        $assignedPriceListId = $this->createPriceList($tenantId, [
            'code' => 'DROGERIA-PREFERENTE',
            'priority' => 10,
        ]);

        $this->createPriceListItem($defaultPriceListId, $productId, 14.00);
        $this->createPriceListItem($assignedPriceListId, $productId, 12.50);
        $this->assignPriceListToCustomer($tenantId, $customerId, $assignedPriceListId, 1);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 3]],
            $customerId,
            'retail',
        );

        $this->assertSame('customer_assignment', $resolved['items'][0]['commercial_context']['price_source']);
        $this->assertSame($assignedPriceListId, $resolved['price_list']['id']);
        $this->assertSame('DROGERIA-PREFERENTE', $resolved['price_list']['code']);
        $this->assertSame(12.5, $resolved['items'][0]['base_unit_price']);
        $this->assertSame(37.5, $resolved['items'][0]['line_total']);
        $this->assertSame('base_price', $resolved['items'][0]['adjustments'][0]['adjustment_type']);
    }

    public function test_it_falls_back_to_tenant_default_price_list_when_customer_assignment_is_not_available(): void
    {
        $tenantId = $this->createTenant();
        $customerId = $this->createCustomer($tenantId);
        $productId = $this->createProduct($tenantId, 'IBU-400');
        $defaultPriceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);
        $expiredAssignedListId = $this->createPriceList($tenantId, [
            'code' => 'MAYORISTA-EXP',
        ]);

        $this->createPriceListItem($defaultPriceListId, $productId, 9.90);
        $this->createPriceListItem($expiredAssignedListId, $productId, 8.50);
        $this->assignPriceListToCustomer($tenantId, $customerId, $expiredAssignedListId, 1, [
            'ends_at' => now()->subDay(),
        ]);

        $resolved = app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 2]],
            $customerId,
            'retail',
        );

        $this->assertSame('tenant_default', $resolved['items'][0]['commercial_context']['price_source']);
        $this->assertSame($defaultPriceListId, $resolved['price_list']['id']);
        $this->assertSame(9.9, $resolved['items'][0]['base_unit_price']);
        $this->assertSame(19.8, $resolved['items'][0]['line_total']);
    }

    public function test_it_rejects_quote_when_selected_price_list_has_no_active_price_for_a_product(): void
    {
        $tenantId = $this->createTenant();
        $customerId = $this->createCustomer($tenantId);
        $productId = $this->createProduct($tenantId, 'AMOX-500');
        $defaultPriceListId = $this->createPriceList($tenantId, [
            'code' => 'RETAIL-BASE',
            'is_default' => true,
        ]);
        $assignedPriceListId = $this->createPriceList($tenantId, [
            'code' => 'CADENA-A',
        ]);

        $this->createPriceListItem($defaultPriceListId, $productId, 11.80);
        $this->assignPriceListToCustomer($tenantId, $customerId, $assignedPriceListId, 1);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('does not define an active base price');

        app(PriceListResolverService::class)->resolveForQuote(
            $tenantId,
            [['product_id' => $productId, 'quantity' => 1]],
            $customerId,
            'retail',
        );
    }

    private function createTenant(): int
    {
        return DB::table('tenants')->insertGetId([
            'code' => 'tenant-pricing',
            'name' => 'Tenant Pricing',
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
            'document_number' => '20100000001',
            'name' => 'Cadena Demo',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProduct(int $tenantId, string $sku): int
    {
        return DB::table('products')->insertGetId([
            'tenant_id' => $tenantId,
            'sku' => $sku,
            'name' => 'Producto '.$sku,
            'status' => 'active',
            'commercial_status' => 'active',
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

    private function assignPriceListToCustomer(int $tenantId, int $customerId, int $priceListId, int $priority, array $overrides = []): int
    {
        return DB::table('customer_price_list_assignments')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'price_list_id' => $priceListId,
            'priority' => $priority,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
