<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rastro de revisão anual por equipamento (api#136) — mesmo espírito de idempotência de
     * order_stalled_alerts/order_equipment_situation_alerts, chaveado direto por equipment_id do
     * catálogo (não por uma linha efêmera de order_equipments, ver api#149/PR #155). order_id é
     * só referência pro e-mail e pro GET de listagem, não entra na chave.
     *
     * FKs cross-module (Orders, Equipments) — cascade, não nullOnDelete: esta tabela é só rastro
     * de idempotência/histórico interno, nunca exibida como snapshot de OS.
     */
    public function up(): void
    {
        Schema::create('equipment_revision_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->timestamp('base_date');
            $table->string('milestone');
            $table->timestamp('due_date');
            $table->timestamp('notified_at');
            $table->timestamp('client_contacted_at')->nullable();
            $table->timestamp('billing_notified_at')->nullable();
            // Ciclo superado por uma resolução mais recente do mesmo equipamento (Q2) — barra só
            // a cobrança de 7 dias de um marco já disparado e ainda sem contato (um marco que
            // nunca chegou a disparar simplesmente não é mais elegível, o comando não o recria).
            // Linha fica pra histórico, só sai da fila de cobrança/listagem.
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            // Nome explícito: o gerado pelo Laravel passa dos 64 caracteres que o MySQL aceita.
            $table->unique(['equipment_id', 'base_date', 'milestone'], 'equipment_revision_alerts_unique_cycle');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_revision_alerts');
    }
};
