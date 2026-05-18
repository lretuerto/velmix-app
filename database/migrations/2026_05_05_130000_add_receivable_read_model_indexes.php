<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_receivables', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'due_at', 'id'], 'receivables_status_due_idx');
            $table->index(['tenant_id', 'customer_id', 'status', 'id'], 'receivables_customer_status_idx');
            $table->index(['tenant_id', 'outstanding_amount', 'due_at'], 'receivables_outstanding_due_idx');
        });

        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->index(['sale_receivable_id', 'id'], 'receivable_payments_parent_idx');
        });

        Schema::table('sale_receivable_follow_ups', function (Blueprint $table) {
            $table->index(['tenant_id', 'sale_receivable_id', 'id'], 'receivable_followups_parent_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->index(['tenant_id', 'customer_id', 'id'], 'sales_customer_read_idx');
            $table->index(['tenant_id', 'cash_session_id', 'id'], 'sales_cash_session_read_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_cash_session_read_idx');
            $table->dropIndex('sales_customer_read_idx');
        });

        Schema::table('sale_receivable_follow_ups', function (Blueprint $table) {
            $table->dropIndex('receivable_followups_parent_idx');
        });

        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->dropIndex('receivable_payments_parent_idx');
        });

        Schema::table('sale_receivables', function (Blueprint $table) {
            $table->dropIndex('receivables_outstanding_due_idx');
            $table->dropIndex('receivables_customer_status_idx');
            $table->dropIndex('receivables_status_due_idx');
        });
    }
};
