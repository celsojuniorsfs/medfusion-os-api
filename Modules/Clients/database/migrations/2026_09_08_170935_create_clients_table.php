<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_name'); // razão social
            $table->string('tax_id'); // CNPJ
            $table->string('requester')->nullable(); // solicitante
            $table->string('department')->nullable(); // setor
            $table->string('phone')->nullable(); // telefone
            $table->string('address')->nullable(); // endereço
            $table->string('city')->nullable(); // cidade
            $table->string('postal_code')->nullable(); // CEP
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
