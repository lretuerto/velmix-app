<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_credit_notes', function (Blueprint $table) {
            $table->dropUnique(['sale_id']);
            $table->index(['sale_id', 'id']);
        });

        Schema::create('sale_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_credit_note_id')->constrained('sale_credit_notes')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index(['sale_credit_note_id', 'sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_credit_note_items');

        Schema::table('sale_credit_notes', function (Blueprint $table) {
            $table->dropIndex(['sale_id', 'id']);
            $table->unique('sale_id');
        });
    }
};
