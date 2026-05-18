<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->string('channel')->default('retail');
            $table->string('payment_method');
            $table->string('status')->default('draft');
            $table->string('quote_hash');
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('PEN');
            $table->timestamp('expires_at');
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'quote_hash']);
            $table->index(['tenant_id', 'status', 'expires_at']);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
        });

        Schema::create('pricing_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_quote_id')->constrained('pricing_quotes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('requested_quantity');
            $table->foreignId('resolved_price_list_item_id')->nullable()->constrained('price_list_items')->nullOnDelete();
            $table->decimal('base_unit_price', 12, 2);
            $table->decimal('final_unit_price', 12, 2);
            $table->decimal('line_discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->json('commercial_context')->nullable();
            $table->timestamps();

            $table->index(['pricing_quote_id', 'product_id']);
        });

        Schema::create('pricing_quote_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_quote_item_id')->constrained('pricing_quote_items')->cascadeOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->foreignId('promotion_rule_id')->nullable()->constrained('promotion_rules')->nullOnDelete();
            $table->string('adjustment_type');
            $table->string('description');
            $table->foreignId('sponsor_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->decimal('unit_delta', 12, 2)->default(0);
            $table->decimal('total_delta', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['pricing_quote_item_id', 'adjustment_type'], 'pricing_adj_quote_item_type_idx');
            $table->index(['promotion_id', 'promotion_rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_quote_adjustments');
        Schema::dropIfExists('pricing_quote_items');
        Schema::dropIfExists('pricing_quotes');
    }
};
