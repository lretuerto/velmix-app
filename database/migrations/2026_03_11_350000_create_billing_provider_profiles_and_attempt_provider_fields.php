<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider_code');
            $table->string('environment')->default('sandbox');
            $table->string('default_outcome')->default('accepted');
            $table->json('credentials')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::table('outbox_attempts', function (Blueprint $table) {
            $table->string('provider_code')->nullable()->after('status');
            $table->string('provider_reference')->nullable()->after('sunat_ticket');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_attempts', function (Blueprint $table) {
            $table->dropColumn(['provider_code', 'provider_reference']);
        });

        Schema::dropIfExists('billing_provider_profiles');
    }
};
