<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->string('error_class')->nullable();
            $table->string('request_fingerprint_version', 16)->default('v1');

            $table->index(['tenant_id', 'status', 'locked_until'], 'idempotency_processing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropIndex('idempotency_processing_idx');
            $table->dropColumn([
                'completed_at',
                'error_class',
                'request_fingerprint_version',
            ]);
        });
    }
};
