<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->index(['tenant_id', 'opened_at', 'closed_at', 'id'], 'cash_sessions_tenant_window_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['tenant_id', 'payment_method', 'id'], 'sales_cash_backfill_idx');
        });

        Schema::table('sale_refunds', function (Blueprint $table) {
            $table->index(['tenant_id', 'payment_method', 'cash_session_id', 'id'], 'sale_refunds_cash_backfill_idx');
        });

        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->index(['payment_method', 'id'], 'receivable_payments_cash_backfill_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->dropIndex('receivable_payments_cash_backfill_idx');
        });

        Schema::table('sale_refunds', function (Blueprint $table) {
            $table->dropIndex('sale_refunds_cash_backfill_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_cash_backfill_idx');
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropIndex('cash_sessions_tenant_window_idx');
        });
    }
};
