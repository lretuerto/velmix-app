<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_provider_profiles', function (Blueprint $table) {
            $table->string('health_status')->default('unknown')->after('credentials');
            $table->timestamp('health_checked_at')->nullable()->after('health_status');
            $table->text('health_message')->nullable()->after('health_checked_at');
        });

        Schema::table('outbox_attempts', function (Blueprint $table) {
            $table->string('provider_environment')->nullable()->after('provider_code');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_attempts', function (Blueprint $table) {
            $table->dropColumn('provider_environment');
        });

        Schema::table('billing_provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['health_status', 'health_checked_at', 'health_message']);
        });
    }
};
