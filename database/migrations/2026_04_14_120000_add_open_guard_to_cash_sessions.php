<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->string('open_guard')->nullable()->after('status');
        });

        DB::table('cash_sessions')
            ->where('status', 'open')
            ->orderBy('id')
            ->get(['id', 'tenant_id'])
            ->each(function (object $session): void {
                DB::table('cash_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'open_guard' => sprintf('tenant:%d', $session->tenant_id),
                    ]);
            });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->unique('open_guard', 'cash_sessions_open_guard_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropUnique('cash_sessions_open_guard_unique');
            $table->dropColumn('open_guard');
        });
    }
};
