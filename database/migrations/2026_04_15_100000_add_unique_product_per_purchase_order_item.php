<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('purchase_order_items')
            ->select('purchase_order_id', 'product_id')
            ->groupBy('purchase_order_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Duplicate purchase order items found for the same product within a purchase order.');
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unique(
                ['purchase_order_id', 'product_id'],
                'purchase_order_items_order_product_unique',
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->index('purchase_order_id', 'purchase_order_items_order_fk_idx');
            });
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropUnique('purchase_order_items_order_product_unique');
        });
    }
};
