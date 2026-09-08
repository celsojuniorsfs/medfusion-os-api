<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique(); // número da OS
            $table->date('date');
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Tipo de atendimento — checkboxes não excludentes.
            $table->boolean('picked_up')->default(false); // retirado
            $table->boolean('warranty')->default(false); // garantia
            $table->boolean('technical_training')->default(false); // treinamento técnico
            $table->boolean('on_site_quote')->default(false); // orç. local
            $table->boolean('rental')->default(false); // locação

            $table->text('reported_defect')->nullable(); // defeito apresentado
            $table->text('maintenance_plan')->nullable(); // manutenção a aplicar
            $table->text('notes')->nullable(); // observação

            $table->string('payment_method')->nullable(); // forma de pagamento
            $table->string('warranty_period')->nullable(); // garantia (prazo)
            $table->string('proposal_validity')->nullable(); // validade da proposta

            $table->decimal('labor_cost', 10, 2)->nullable(); // valor da mão de obra
            $table->decimal('total', 10, 2)->default(0);

            // String curta + validação na aplicação (decidido na F3) — evita migration a cada
            // novo status. Fluxo completo (9 estados) em api-conventions.md § Status da OS.
            $table->string('status')->default('open');

            $table->string('certificate_number')->nullable(); // nº do certificado

            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
