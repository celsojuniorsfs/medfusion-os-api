<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de equipamentos por cliente — decidido na validação de escopo: reaproveitado
        // entre OS's, sem limite de quantidade (ver escopo-v1.md § Modelo de dados da v1).
        Schema::create('equipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // equipamento (ex.: "Bisturi")
            $table->string('brand')->nullable(); // marca
            $table->string('model')->nullable(); // modelo
            $table->string('serial_number')->nullable(); // número de série
            $table->string('asset_tag')->nullable(); // patrimônio
            $table->string('accessories')->nullable(); // acessórios
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipments');
    }
};
