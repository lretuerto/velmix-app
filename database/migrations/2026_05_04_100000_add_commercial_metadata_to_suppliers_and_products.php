<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('kind')->default('distributor')->after('name');
            $table->string('commercial_code')->nullable()->after('kind');

            $table->index(['tenant_id', 'kind']);
            $table->unique(['tenant_id', 'commercial_code']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('laboratory_supplier_id')
                ->nullable()
                ->after('average_cost')
                ->constrained('suppliers')
                ->nullOnDelete();
            $table->string('commercial_status')->default('active')->after('laboratory_supplier_id');

            $table->index(['tenant_id', 'commercial_status']);
            $table->index(['tenant_id', 'laboratory_supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'commercial_status']);
            $table->dropIndex(['tenant_id', 'laboratory_supplier_id']);
            $table->dropConstrainedForeignId('laboratory_supplier_id');
            $table->dropColumn('commercial_status');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'commercial_code']);
            $table->dropIndex(['tenant_id', 'kind']);
            $table->dropColumn(['kind', 'commercial_code']);
        });
    }
};
