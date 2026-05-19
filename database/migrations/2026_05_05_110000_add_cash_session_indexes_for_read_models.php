<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'id'], 'cash_sessions_tenant_status_id_idx');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->index(['tenant_id', 'cash_session_id', 'created_at'], 'cash_movements_session_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropIndex('cash_movements_session_time_idx');
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropIndex('cash_sessions_tenant_status_id_idx');
        });
    }
};
