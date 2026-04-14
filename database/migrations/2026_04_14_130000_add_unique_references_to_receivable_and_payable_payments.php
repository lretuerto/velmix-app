<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureNoDuplicateReferences(
            'sale_receivable_payments',
            'sale_receivable_id',
            'Cobranza duplicada encontrada para la misma cuenta por cobrar.',
        );

        $this->ensureNoDuplicateReferences(
            'purchase_payments',
            'purchase_payable_id',
            'Pago duplicado encontrado para la misma cuenta por pagar.',
        );

        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->unique(
                ['sale_receivable_id', 'reference'],
                'sale_receivable_payments_receivable_reference_unique',
            );
        });

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->unique(
                ['purchase_payable_id', 'reference'],
                'purchase_payments_payable_reference_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sale_receivable_payments', function (Blueprint $table) {
            $table->dropUnique('sale_receivable_payments_receivable_reference_unique');
        });

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropUnique('purchase_payments_payable_reference_unique');
        });
    }

    private function ensureNoDuplicateReferences(string $table, string $parentColumn, string $message): void
    {
        $duplicate = DB::table($table)
            ->select($parentColumn, 'reference')
            ->groupBy($parentColumn, 'reference')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException($message);
        }
    }
};
