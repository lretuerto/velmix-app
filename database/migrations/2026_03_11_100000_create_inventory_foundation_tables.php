<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->string('status')->default('active');
            $table->boolean('is_controlled')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code');
            $table->date('expires_at');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->string('status')->default('available');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
        Schema::dropIfExists('products');
    }
};
