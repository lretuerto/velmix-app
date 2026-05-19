<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_vouchers', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('sunat_ticket');
        });

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->unsignedInteger('retry_count')->default(0)->after('status');
            $table->text('last_error')->nullable()->after('retry_count');
        });

        Schema::create('outbox_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_event_id')->constrained('outbox_events')->cascadeOnDelete();
            $table->string('status');
            $table->string('sunat_ticket')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['outbox_event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_attempts');

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'last_error']);
        });

        Schema::table('electronic_vouchers', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
