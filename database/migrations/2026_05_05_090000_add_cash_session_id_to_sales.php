<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_session_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('cash_sessions')
                ->nullOnDelete();

            $table->index(['tenant_id', 'cash_session_id', 'id'], 'sales_tenant_cash_session_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_tenant_cash_session_idx');
            $table->dropConstrainedForeignId('cash_session_id');
        });
    }
};
