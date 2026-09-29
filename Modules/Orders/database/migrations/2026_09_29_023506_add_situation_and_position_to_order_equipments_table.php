<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Orders\Domain\Events\OrderStatusChanged;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->string('situation')->default('in_analysis')->after('calibration');
            $table->timestamp('situation_changed_at')->nullable()->after('situation');
            $table->timestamp('completed_at')->nullable()->after('situation_changed_at');
            $table->unsignedInteger('position')->default(0)->after('completed_at');
        });

        // situation_changed_at começa em created_at pra quem não tem histórico melhor — "entrou
        // na OS e nunca mudou de situação" é a leitura correta pra todo equipamento existente
        // (o conceito de situação não existia antes desta migration, api#140).
        DB::table('order_equipments')->update(['situation_changed_at' => DB::raw('created_at')]);

        // position: ordem de chegada dentro de cada OS, mesmo critério que o projector passa a
        // usar daqui pra frente (contar quantos já existiam) — determinística por created_at/id.
        // Vira a letra do certificado (api#61: 0 = A, 1 = B...).
        DB::table('orders')
            ->select('id')
            ->orderBy('id')
            ->each(function ($order) {
                $equipmentIds = DB::table('order_equipments')
                    ->where('order_id', $order->id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($equipmentIds->values() as $position => $id) {
                    DB::table('order_equipments')->where('id', $id)->update(['position' => $position]);
                }
            });

        // Backfill de situação/conclusão pra OS's já `completed`/`warranty_repair`: a situação
        // por equipamento não existia antes desta migration, então sem isso todo equipamento
        // delas ficaria preso em `in_analysis` pra sempre (ninguém vai mudar a situação de uma OS
        // que já está concluída). completed_at usa a data REAL da conclusão, lida direto do
        // event store (último OrderStatusChanged pra "completed" de cada OS) em vez de now() —
        // mesmo cuidado já usado na migration de status_changed_at (api#135) — com
        // orders.status_changed_at como default pro caso raro de não achar o evento.
        $lastCompletedAt = [];

        DB::table('stored_events')
            ->where('event_class', OrderStatusChanged::class)
            ->select('aggregate_uuid', 'event_properties', 'created_at')
            ->orderBy('aggregate_uuid')
            ->orderBy('id')
            ->each(function ($row) use (&$lastCompletedAt) {
                $properties = json_decode($row->event_properties, true);

                if (($properties['to'] ?? null) === 'completed') {
                    $lastCompletedAt[$row->aggregate_uuid] = $row->created_at;
                }
            });

        DB::table('orders')
            ->whereIn('status', ['completed', 'warranty_repair'])
            ->select('id', 'status_changed_at')
            ->orderBy('id')
            ->each(function ($order) use ($lastCompletedAt) {
                $completedAt = $lastCompletedAt[$order->id] ?? $order->status_changed_at;

                DB::table('order_equipments')
                    ->where('order_id', $order->id)
                    ->update([
                        'situation' => 'completed',
                        'situation_changed_at' => $completedAt,
                        'completed_at' => $completedAt,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_equipments', function (Blueprint $table) {
            $table->dropColumn(['situation', 'situation_changed_at', 'completed_at', 'position']);
        });
    }
};
