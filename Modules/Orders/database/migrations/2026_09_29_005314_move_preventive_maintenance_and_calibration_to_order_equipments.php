<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
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

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['preventive_maintenance', 'calibration']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('preventive_maintenance')->default(false)->after('rental');
            $table->boolean('calibration')->default(false)->after('preventive_maintenance');
        });

        // Backfill reverso: OS marcada como preventiva/calibração se QUALQUER equipamento dela
        // tinha o campo — perde granularidade (esperado, é a direção errada da migração).
        DB::table('order_equipments')
            ->select('order_id')
            ->where('preventive_maintenance', true)
            ->distinct()
            ->orderBy('order_id')
            ->each(fn ($row) => DB::table('orders')->where('id', $row->order_id)->update(['preventive_maintenance' => true]));

        DB::table('order_equipments')
            ->select('order_id')
            ->where('calibration', true)
            ->distinct()
            ->orderBy('order_id')
            ->each(fn ($row) => DB::table('orders')->where('id', $row->order_id)->update(['calibration' => true]));

        Schema::table('order_equipments', function (Blueprint $table) {
            $table->dropColumn(['preventive_maintenance', 'calibration']);
        });
    }
};
