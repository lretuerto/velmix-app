<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('method', 10);
            $table->string('path');
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->string('status')->default('in_progress');
            $table->timestamp('locked_until')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'method', 'path', 'idempotency_key'], 'idempotency_scope_unique');
            $table->index(['tenant_id', 'status'], 'idempotency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
