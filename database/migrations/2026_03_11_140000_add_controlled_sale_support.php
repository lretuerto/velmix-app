<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('prescription_code')->nullable()->after('line_total');
            $table->string('approval_code')->nullable()->after('prescription_code');
        });

        Schema::create('sale_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('reason');
            $table->string('status')->default('approved');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_approvals');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['prescription_code', 'approval_code']);
        });
    }
};
