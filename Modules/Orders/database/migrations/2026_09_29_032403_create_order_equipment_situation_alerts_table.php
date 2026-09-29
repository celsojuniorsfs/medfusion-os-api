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
        // Rastro de idempotência do comando agendado `orders:check-equipment-situations`
        // (api#147) — mesmo desenho de order_stalled_alerts (api#135), só que por equipamento em
        // vez de por OS. situation_changed_at ancora "esta passagem do equipamento por esta
        // situação" — se ele sai da situação e volta depois, vira uma passagem nova (valor
        // diferente), reabrindo os marcos, igual a contagem reinicia por mudança de situação
        // (S5, confirmado com o cliente).
        Schema::create('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_equipment_id')->constrained('order_equipments')->cascadeOnDelete();
            $table->string('situation');
            $table->timestamp('situation_changed_at');
            $table->unsignedSmallInteger('milestone_days');
            $table->timestamp('notified_at');

            $table->unique(
                ['order_equipment_id', 'situation_changed_at', 'milestone_days'],
                'order_equipment_situation_alerts_unique_milestone',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_equipment_situation_alerts');
    }
};
