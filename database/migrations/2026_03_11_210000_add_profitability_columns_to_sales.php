<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('gross_cost', 12, 2)->default(0)->after('total_amount');
            $table->decimal('gross_margin', 12, 2)->default(0)->after('gross_cost');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 12, 2)->default(0)->after('unit_price');
            $table->decimal('cost_amount', 12, 2)->default(0)->after('line_total');
            $table->decimal('gross_margin', 12, 2)->default(0)->after('cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_snapshot', 'cost_amount', 'gross_margin']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['gross_cost', 'gross_margin']);
        });
    }
};
