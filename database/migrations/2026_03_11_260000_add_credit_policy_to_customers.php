<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 12, 2)->nullable()->after('email');
            $table->unsignedInteger('credit_days')->nullable()->after('credit_limit');
            $table->boolean('block_on_overdue')->default(true)->after('credit_days');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'credit_days', 'block_on_overdue']);
        });
    }
};
