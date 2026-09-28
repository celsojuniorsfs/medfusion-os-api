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
        // Rastro de idempotência do comando agendado (api#135): garante que cada marco (7, 15,
        // 30... dias) de uma mesma passagem pela OS parada num status só dispara um e-mail, mesmo
        // rodando o comando todo dia. status_changed_at ancora "a passagem" — se a OS sai do
        // status e volta pra ele depois, vira uma passagem nova (status_changed_at diferente),
        // reabrindo os marcos, igual a contagem reinicia por status combinada com o cliente.
        Schema::create('order_stalled_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('status_changed_at');
            $table->unsignedSmallInteger('milestone_days');
            $table->timestamp('notified_at');

            $table->unique(['order_id', 'status_changed_at', 'milestone_days'], 'order_stalled_alerts_unique_milestone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_stalled_alerts');
    }
};
