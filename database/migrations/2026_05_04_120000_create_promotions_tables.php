<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('sponsor_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('channel')->default('retail');
            $table->unsignedInteger('priority')->default(100);
            $table->string('stack_mode')->default('best_price_only');
            $table->boolean('stop_further_processing')->default(false);
            $table->boolean('requires_customer')->default(false);
            $table->json('allowed_payment_methods')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->decimal('budget_cap', 12, 2)->nullable();
            $table->decimal('budget_used', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'channel']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->boolean('exclude')->default(false);
            $table->timestamps();

            $table->index(['promotion_id', 'target_type']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('rule_type');
            $table->string('scope')->default('line');
            $table->json('config');
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['promotion_id', 'status']);
            $table->index(['promotion_id', 'rule_type']);
        });

        Schema::create('promotion_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('audience_type');
            $table->unsignedBigInteger('audience_id')->nullable();
            $table->timestamps();

            $table->index(['promotion_id', 'audience_type']);
            $table->index(['audience_type', 'audience_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_audiences');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
