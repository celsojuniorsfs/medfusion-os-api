<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * order_equipment_id é efêmero (troca a cada PUT na OS, api#149) — chavear por ele faz um
     * marco já avisado ser reenviado depois de qualquer edição, porque as linhas antigas são
     * apagadas em cascata mas situation_changed_at é preservado pelo UpdateOrder. Troca pra
     * order_id + equipment_id (catálogo, estável) — mesmo raciocínio de order_pdf_equipments
     * (api#149).
     */
    public function up(): void
    {
        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->foreignUuid('order_id')->nullable()->after('id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('equipment_id')->nullable()->after('order_id')->constrained('equipments')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE order_equipment_situation_alerts
            SET order_id = (SELECT order_id FROM order_equipments WHERE order_equipments.id = order_equipment_situation_alerts.order_equipment_id),
                equipment_id = (SELECT equipment_id FROM order_equipments WHERE order_equipments.id = order_equipment_situation_alerts.order_equipment_id)
        ');

        // Equipamento sem vínculo com o catálogo (removido dele) não tem identidade estável pra
        // reidratar o alerta — descarta o rastro de idempotência, o próximo run recomeça limpo.
        DB::table('order_equipment_situation_alerts')->whereNull('equipment_id')->delete();

        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->dropUnique('order_equipment_situation_alerts_unique_milestone');
            $table->dropConstrainedForeignId('order_equipment_id');
            $table->unique(
                ['order_id', 'equipment_id', 'situation_changed_at', 'milestone_days'],
                'order_equipment_situation_alerts_unique_milestone',
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->dropUnique('order_equipment_situation_alerts_unique_milestone');
            $table->foreignUuid('order_equipment_id')->nullable()->constrained('order_equipments')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE order_equipment_situation_alerts
            SET order_equipment_id = (SELECT id FROM order_equipments WHERE order_equipments.order_id = order_equipment_situation_alerts.order_id AND order_equipments.equipment_id = order_equipment_situation_alerts.equipment_id)
        ');

        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('equipment_id');
            $table->unique(
                ['order_equipment_id', 'situation_changed_at', 'milestone_days'],
                'order_equipment_situation_alerts_unique_milestone',
            );
        });
    }
};
