<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_session_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('entry_type');
            $table->string('direction');
            $table->decimal('amount', 12, 2);
            $table->string('reference');
            $table->string('notes')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'entry_type'], 'cash_ledger_source_unique');
            $table->index(['tenant_id', 'cash_session_id', 'occurred_at'], 'cash_ledger_session_time_idx');
            $table->index(['tenant_id', 'cash_session_id', 'entry_type'], 'cash_ledger_session_type_idx');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'cash_ledger_source_lookup_idx');
            $table->index(['tenant_id', 'occurred_at'], 'cash_ledger_tenant_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_session_ledger_entries');
    }
};
