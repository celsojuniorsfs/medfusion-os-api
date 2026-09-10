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
            // 'individual' ou 'company' — Domain\Enums\PersonType. string(), não enum() nativo do
            // banco, mesmo tratamento já dado a orders.status (evita ALTER TYPE no dia em que um
            // terceiro tipo aparecer).
            $table->string('person_type');
            $table->string('name'); // nome completo (pessoa física) ou razão social (pessoa jurídica)
            $table->string('trade_name')->nullable(); // nome fantasia (só pessoa jurídica)
            // CPF (11 dígitos) ou CNPJ (14), só números — a pontuação fica por conta do frontend
            // na exibição (ver Presentation/Http/Requests/ClientRequest::prepareForValidation).
            $table->string('tax_id', 14)->unique();
            $table->string('state_registration')->nullable(); // inscrição estadual (só pessoa jurídica; "ISENTO" é valor válido)
            $table->string('requester')->nullable(); // solicitante
            $table->string('department')->nullable(); // setor
            $table->string('phone')->nullable(); // telefone
            $table->string('email')->nullable();
            $table->string('address')->nullable(); // endereço
            $table->string('city')->nullable(); // cidade
            $table->char('state', 2)->nullable(); // UF
            $table->string('postal_code', 8)->nullable(); // CEP, só números
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
