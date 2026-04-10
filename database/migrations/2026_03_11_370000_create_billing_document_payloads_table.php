<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_document_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('provider_code');
            $table->string('provider_environment');
            $table->string('schema_version');
            $table->string('document_kind');
            $table->string('document_number');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id'], 'billing_payloads_tenant_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_payloads');
    }
};
