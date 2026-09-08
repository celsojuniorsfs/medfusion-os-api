<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 8, 2); // quantidade
            $table->string('description'); // descrição
            // Opcional — corrigido na validação: prefeituras pedem orçamento só com valor de
            // mão de obra (orders.labor_cost), peça embutida.
            $table->decimal('unit_price', 10, 2)->nullable(); // valor unitário
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
