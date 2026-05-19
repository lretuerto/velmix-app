<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_receivable_follow_ups', function (Blueprint $table) {
            $table->decimal('outstanding_snapshot', 12, 2)->nullable()->after('promised_amount');
        });

        Schema::table('purchase_payable_follow_ups', function (Blueprint $table) {
            $table->decimal('outstanding_snapshot', 12, 2)->nullable()->after('promised_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payable_follow_ups', function (Blueprint $table) {
            $table->dropColumn('outstanding_snapshot');
        });

        Schema::table('sale_receivable_follow_ups', function (Blueprint $table) {
            $table->dropColumn('outstanding_snapshot');
        });
    }
};
