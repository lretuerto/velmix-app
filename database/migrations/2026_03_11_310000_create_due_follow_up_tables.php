<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_receivable_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_receivable_id')->constrained('sale_receivables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->text('note');
            $table->decimal('promised_amount', 12, 2)->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sale_receivable_id']);
            $table->index(['tenant_id', 'promised_at']);
        });

        Schema::create('purchase_payable_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('purchase_payable_id')->constrained('purchase_payables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');
            $table->text('note');
            $table->decimal('promised_amount', 12, 2)->nullable();
            $table->timestamp('promised_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_payable_id']);
            $table->index(['tenant_id', 'promised_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payable_follow_ups');
        Schema::dropIfExists('sale_receivable_follow_ups');
    }
};
