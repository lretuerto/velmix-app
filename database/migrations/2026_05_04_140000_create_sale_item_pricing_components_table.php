<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_pricing_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('pricing_quote_item_id')->nullable()->constrained('pricing_quote_items')->nullOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->foreignId('promotion_rule_id')->nullable()->constrained('promotion_rules')->nullOnDelete();
            $table->string('component_type');
            $table->string('description');
            $table->foreignId('sponsor_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('unit_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sale_item_id', 'component_type']);
            $table->index(['promotion_id', 'promotion_rule_id'], 'sale_item_pricing_promo_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_pricing_components');
    }
};
