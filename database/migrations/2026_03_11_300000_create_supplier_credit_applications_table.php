<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('supplier_credit_id')->constrained('supplier_credits')->cascadeOnDelete();
            $table->foreignId('purchase_payable_id')->constrained('purchase_payables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('application_type')->default('manual');
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_payable_id']);
            $table->index(['supplier_credit_id', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_applications');
    }
};
