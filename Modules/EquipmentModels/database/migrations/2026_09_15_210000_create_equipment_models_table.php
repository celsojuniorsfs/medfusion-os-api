<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Sem unique de propósito, mesma decisão já tomada pro nome de Accessory e pro
            // serial_number de Equipment — ver EquipmentModelRequest.
            $table->string('name');
            // Nullable no banco embora obrigatórios na validação de entrada: o backfill cria
            // entradas a partir dos equipamentos que já existem, e os cadastrados antes do api#92
            // podem ter ficado sem marca/modelo.
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_models');
    }
};
