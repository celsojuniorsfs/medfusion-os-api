<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_equipment_accessories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'order_equipments' explícito, mesmo motivo de order_equipments.equipment_id.
            $table->foreignUuid('order_equipment_id')->constrained('order_equipments')->cascadeOnDelete();

            // Sem FK pro catálogo global de Accessories (ao contrário de equipment_accessories):
            // snapshot livre digitado nesta OS, decisão explícita pra não criar uma dependência
            // nova de Orders -&gt; Accessories no grafo de módulos (ver CLAUDE.md § Grafo).
            $table->string('name');
            $table->unsignedInteger('quantity');
            // uuid como PK não garante nenhuma ordem de leitura — position preserva a ordem em
            // que o acessório foi digitado (ver OrderEquipment::accessories()).
            $table->unsignedSmallInteger('position');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_equipment_accessories');
    }
};
