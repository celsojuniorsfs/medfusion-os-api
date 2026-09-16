<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            // Dependência cross-module de verdade (FK pra tabela do módulo EquipmentModels), do
            // mesmo tipo já registrada em equipment_accessories.accessory_id e em
            // order_equipments.equipment_id — nenhum `use Modules\...` em PHP a revelaria.
            //
            // Nullable de forma permanente, não "por enquanto": equipamentos cadastrados antes do
            // api#101 têm eventos sem id de modelo nenhum, e o EquipmentProjector reconstrói essas
            // linhas num replay resolvendo pelo trio nome/marca/modelo — quando não acha, null é o
            // valor correto (significa "modelo desconhecido", não "sem modelo").
            //
            // restrictOnDelete pela mesma razão de equipment_accessories.accessory_id: apagar uma
            // entrada de catálogo que está em uso deve falhar, não sumir com o vínculo. Hoje nem
            // existe endpoint de remoção do catálogo — é o comportamento certo pra quando existir.
            $table->foreignUuid('equipment_model_id')
                ->nullable()
                ->after('client_id')
                ->constrained('equipment_models')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipments', function (Blueprint $table) {
            $table->dropForeign(['equipment_model_id']);
            $table->dropColumn('equipment_model_id');
        });
    }
};
