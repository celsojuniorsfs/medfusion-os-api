<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Divide o texto livre que já existia em order_equipments.accessories (ex.: "Cabo de força,
 * pedal") em várias linhas de order_equipment_accessories, uma por pedaço, quantity 1 — mesma
 * regra de Modules\Orders\Domain\OrderEquipmentAccessories::fromLegacyText(), copiada aqui de
 * propósito (não chamando a classe): uma migration já rodada em produção não deve mudar de
 * comportamento se aquele helper for editado depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_equipments')
            ->whereNotNull('accessories')
            ->where('accessories', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                $now = now();

                foreach ($rows as $row) {
                    $names = array_filter(
                        array_map('trim', preg_split('/[,;]/', $row->accessories)),
                        fn (string $name) => $name !== '',
                    );

                    $accessories = [];
                    foreach (array_values($names) as $position => $name) {
                        $accessories[] = [
                            'id' => (string) Str::uuid(),
                            'order_equipment_id' => $row->id,
                            'name' => $name,
                            'quantity' => 1,
                            'position' => $position,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($accessories !== []) {
                        DB::table('order_equipment_accessories')->insert($accessories);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('order_equipments')
            ->orderBy('id')
            ->chunkById(100, function ($rows) {
                foreach ($rows as $row) {
                    $names = DB::table('order_equipment_accessories')
                        ->where('order_equipment_id', $row->id)
                        ->orderBy('position')
                        ->pluck('name');

                    if ($names->isNotEmpty()) {
                        DB::table('order_equipments')
                            ->where('id', $row->id)
                            ->update(['accessories' => $names->implode(', ')]);
                    }
                }
            });

        DB::table('order_equipment_accessories')->delete();
    }
};
