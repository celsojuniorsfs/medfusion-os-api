<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * orders.preventive_maintenance/calibration (api#134) NÃO são removidas aqui, mesmo saindo
     * de uso na API — o OrderProjector continua escrevendo essas colunas a partir de
     * OrderOpened/OrderUpdated só pra servir de fallback em onOrderEquipmentAttached() num
     * `event-sourcing:replay`: eventos OrderEquipmentAttached gravados antes desta migration não
     * carregam preventiveMaintenance/calibration (chega null no projector), e sem essa coluna
     * ainda existindo em orders não haveria de onde recuperar o valor histórico verdadeiro — o
     * backfill abaixo é só um atalho pra não esperar um replay completo em bancos já existentes.
     */
    public function up(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->boolean('preventive_maintenance')->default(false)->after('asset_tag');
            $table->boolean('calibration')->default(false)->after('preventive_maintenance');
        });

        // Backfill: nem todo equipamento tem calibração, mas a granularidade que a #134 tinha
        // (por OS) não distinguia isso — copia o valor da OS pra todos os equipamentos dela, pra
        // não perder o que já estava marcado. Mesmo padrão de ->each() da migration de
        // status_changed_at (funciona em SQLite/MySQL, sem depender de UPDATE...JOIN).
        DB::table('orders')
            ->select('id', 'preventive_maintenance', 'calibration')
            ->orderBy('id')
            ->each(function ($order) {
                DB::table('order_equipments')->where('order_id', $order->id)->update([
                    'preventive_maintenance' => $order->preventive_maintenance,
                    'calibration' => $order->calibration,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->dropColumn(['preventive_maintenance', 'calibration']);
        });
    }
};
