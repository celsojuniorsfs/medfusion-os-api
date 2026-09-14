<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_accessories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'equipments' explícito: "equipment" é invariável no plural em inglês — o Eloquent
            // adivinharia "equipment" em vez de "equipments" (mesmo problema já visto na FK de
            // order_equipments.equipment_id).
            $table->foreignUuid('equipment_id')->constrained('equipments')->cascadeOnDelete();
            // Dependência cross-module de verdade (ver CLAUDE.md § Grafo de dependências): FK
            // pra tabela do módulo Accessories. restrictOnDelete (não cascade) — um acessório do
            // catálogo global não deve poder ser apagado enquanto algum equipamento o referencia
            // (mas Accessories não tem endpoint de remoção nesta rodada, então isso é só uma
            // trava defensiva, não um fluxo exercitado hoje).
            $table->foreignUuid('accessory_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_accessories');
    }
};
