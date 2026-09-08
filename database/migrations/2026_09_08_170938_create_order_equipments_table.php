<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vínculo da OS com o catálogo de equipamentos + cópia (snapshot) dos dados no momento
        // da criação — decidido na F3: editar o cadastro depois não reescreve OS's antigas.
        // equipment_id fica nullable (set null) para não travar a remoção de um equipamento do
        // catálogo; o snapshot abaixo continua descrevendo o que foi atendido.
        Schema::create('order_equipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();

            $table->string('name'); // equipamento
            $table->string('brand')->nullable(); // marca
            $table->string('model')->nullable(); // modelo
            $table->string('serial_number')->nullable(); // número de série
            $table->string('asset_tag')->nullable(); // patrimônio
            $table->string('accessories')->nullable(); // acessórios

            $table->timestamps();

            $table->unique(['order_id', 'equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_equipments');
    }
};
