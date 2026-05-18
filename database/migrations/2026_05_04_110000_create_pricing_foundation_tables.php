<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('channel')->default('retail');
            $table->string('currency', 3)->default('PEN');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'channel']);
            $table->index(['tenant_id', 'is_default', 'channel']);
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('min_unit_price', 12, 2)->nullable();
            $table->decimal('max_discount_pct', 5, 2)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['price_list_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['price_list_id', 'product_id']);
        });

        Schema::create('customer_price_list_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'customer_id', 'status'], 'cust_price_assign_tenant_customer_status_idx');
            $table->index(['tenant_id', 'price_list_id', 'status'], 'cust_price_assign_tenant_price_list_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_price_list_assignments');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
