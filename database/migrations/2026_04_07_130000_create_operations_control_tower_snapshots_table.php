<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_control_tower_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('snapshot_date');
            $table->string('label')->nullable();
            $table->string('overall_status', 20);
            $table->unsignedInteger('critical_gate_count')->default(0);
            $table->unsignedInteger('warning_gate_count')->default(0);
            $table->decimal('sales_completed_total', 12, 2)->default(0);
            $table->decimal('collections_total', 12, 2)->default(0);
            $table->decimal('cash_discrepancy_total', 12, 2)->default(0);
            $table->unsignedInteger('billing_pending_backlog_count')->default(0);
            $table->unsignedInteger('billing_failed_backlog_count')->default(0);
            $table->decimal('finance_overdue_total', 12, 2)->default(0);
            $table->unsignedInteger('finance_broken_promise_count')->default(0);
            $table->unsignedInteger('operations_open_alert_count')->default(0);
            $table->unsignedInteger('operations_critical_alert_count')->default(0);
            $table->longText('payload');
            $table->timestamps();

            $table->index(['tenant_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_control_tower_snapshots');
    }
};
