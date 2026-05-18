<?php

namespace Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PricingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_and_promotions_schema_is_available(): void
    {
        $this->assertTrue(Schema::hasColumns('suppliers', [
            'kind',
            'commercial_code',
        ]));

        $this->assertTrue(Schema::hasColumns('products', [
            'laboratory_supplier_id',
            'commercial_status',
        ]));

        $this->assertTrue(Schema::hasTable('price_lists'));
        $this->assertTrue(Schema::hasTable('price_list_items'));
        $this->assertTrue(Schema::hasTable('customer_price_list_assignments'));
        $this->assertTrue(Schema::hasTable('promotions'));
        $this->assertTrue(Schema::hasTable('promotion_targets'));
        $this->assertTrue(Schema::hasTable('promotion_rules'));
        $this->assertTrue(Schema::hasTable('promotion_audiences'));
        $this->assertTrue(Schema::hasTable('pricing_quotes'));
        $this->assertTrue(Schema::hasTable('pricing_quote_items'));
        $this->assertTrue(Schema::hasTable('pricing_quote_adjustments'));
        $this->assertTrue(Schema::hasTable('sale_item_pricing_components'));

        $this->assertTrue(Schema::hasColumns('price_lists', [
            'tenant_id',
            'code',
            'channel',
            'is_default',
            'priority',
        ]));

        $this->assertTrue(Schema::hasColumns('promotions', [
            'sponsor_supplier_id',
            'stack_mode',
            'allowed_payment_methods',
            'budget_cap',
        ]));

        $this->assertTrue(Schema::hasColumns('pricing_quotes', [
            'customer_id',
            'price_list_id',
            'quote_hash',
            'expires_at',
            'created_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumns('sale_item_pricing_components', [
            'sale_item_id',
            'pricing_quote_item_id',
            'promotion_id',
            'component_type',
        ]));
    }
}
