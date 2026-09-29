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
        // Peça por equipamento (api#149) — nullable: null = item geral, sem vínculo com nenhum
        // equipamento específico (ex.: taxa de visita). cascadeOnDelete: se o equipamento é
        // removido da OS (UpdateOrder limpa e reanexa), os itens dele também somem — mesmo
        // comportamento que a OS inteira já tem pra order_items.order_id.
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignUuid('order_equipment_id')->nullable()->after('order_id')
                ->constrained('order_equipments')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_equipment_id');
        });
    }
};
