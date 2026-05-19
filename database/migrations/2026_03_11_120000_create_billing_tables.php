<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('type');
            $table->string('series');
            $table->unsignedInteger('number');
            $table->string('status')->default('pending');
            $table->string('sunat_ticket')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'series', 'number']);
            $table->unique('sale_id');
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('electronic_vouchers');
    }
};
