<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('credited_by_user_id')->nullable()->after('cancelled_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('credit_reason')->nullable()->after('cancel_reason');
            $table->timestamp('credited_at')->nullable()->after('cancelled_at');
        });

        Schema::create('sale_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('electronic_voucher_id')->constrained('electronic_vouchers')->cascadeOnDelete();
            $table->string('series');
            $table->unsignedInteger('number');
            $table->string('status')->default('pending');
            $table->string('reason');
            $table->decimal('total_amount', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('refund_payment_method')->nullable();
            $table->string('sunat_ticket')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique('sale_id');
            $table->unique(['tenant_id', 'series', 'number']);
            $table->index(['tenant_id', 'status', 'id']);
        });

        Schema::create('sale_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('sale_credit_note_id')->constrained('sale_credit_notes')->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained('cash_sessions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('payment_method');
            $table->decimal('amount', 12, 2);
            $table->string('reference');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_refunds');
        Schema::dropIfExists('sale_credit_notes');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credited_by_user_id');
            $table->dropColumn(['credit_reason', 'credited_at']);
        });
    }
};
