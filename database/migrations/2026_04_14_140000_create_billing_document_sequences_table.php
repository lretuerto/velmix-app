<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type');
            $table->string('series');
            $table->unsignedInteger('current_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type', 'series'], 'billing_doc_sequences_scope_unique');
        });

        $now = now();

        $voucherSequences = DB::table('electronic_vouchers')
            ->selectRaw("tenant_id, 'electronic_voucher' as document_type, series, MAX(number) as current_number")
            ->groupBy('tenant_id', 'series')
            ->get();

        $creditNoteSequences = DB::table('sale_credit_notes')
            ->selectRaw("tenant_id, 'sale_credit_note' as document_type, series, MAX(number) as current_number")
            ->groupBy('tenant_id', 'series')
            ->get();

        $rows = collect($voucherSequences)
            ->merge($creditNoteSequences)
            ->map(fn (object $row) => [
                'tenant_id' => $row->tenant_id,
                'document_type' => $row->document_type,
                'series' => $row->series,
                'current_number' => (int) $row->current_number,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows !== []) {
            DB::table('billing_document_sequences')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_document_sequences');
    }
};
