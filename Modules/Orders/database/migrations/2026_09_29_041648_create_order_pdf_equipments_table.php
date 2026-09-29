<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Snapshot, não FK viva (api#149): order_equipments.id troca a cada edição da OS, então
        // um PDF antigo referenciando a linha original ficaria órfão na próxima edição.
        Schema::create('order_pdf_equipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_pdf_id')->constrained('order_pdfs')->cascadeOnDelete();
            // Catálogo (Equipment), não order_equipments — esse sim é estável.
            $table->foreignUuid('equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('position');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_pdf_equipments');
    }
};
