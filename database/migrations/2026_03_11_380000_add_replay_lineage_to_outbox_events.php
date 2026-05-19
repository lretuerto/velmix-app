<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->unsignedBigInteger('replayed_from_event_id')->nullable()->after('last_error');
            $table->index('replayed_from_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex(['replayed_from_event_id']);
            $table->dropColumn('replayed_from_event_id');
        });
    }
};
