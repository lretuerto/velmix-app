<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_cost', 12, 2)->default(0)->after('is_controlled');
            $table->decimal('average_cost', 12, 2)->default(0)->after('last_cost');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['last_cost', 'average_cost']);
        });
    }
};
