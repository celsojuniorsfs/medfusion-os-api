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
        // DDL no MySQL não é transacional: uma execução que falhou no meio deixa colunas/índices
        // pela metade em produção. Cada passo confere o estado real antes de rodar, então re-rodar
        // retoma de onde parou.
        $table = 'order_equipment_situation_alerts';

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'order_id')) {
                $blueprint->foreignUuid('order_id')->nullable()->after('id')->constrained('orders')->cascadeOnDelete();
            }
            // Cross-module (Equipments), mesmo padrão de order_equipments.equipment_id. Cascade,
            // não nullOnDelete como as outras FKs pra equipments: esta tabela é só rastro de
            // idempotência (nunca exibida a usuário) — sem o equipamento no catálogo, a próxima
            // execução do comando já ignora essa linha (whereNotNull('equipment_id')), então
            // mantê-la around com equipment_id nulo não serve pra nada.
            if (! Schema::hasColumn($table, 'equipment_id')) {
                $blueprint->foreignUuid('equipment_id')->nullable()->after('order_id')->constrained('equipments')->cascadeOnDelete();
            }
        });

        if (Schema::hasColumn($table, 'order_equipment_id')) {
            DB::statement('
                UPDATE order_equipment_situation_alerts
                SET order_id = (SELECT order_id FROM order_equipments WHERE order_equipments.id = order_equipment_situation_alerts.order_equipment_id),
                    equipment_id = (SELECT equipment_id FROM order_equipments WHERE order_equipments.id = order_equipment_situation_alerts.order_equipment_id)
            ');
        }

        // Equipamento sem vínculo com o catálogo (removido dele) não tem identidade estável pra
        // reidratar o alerta — descarta o rastro de idempotência, o próximo run recomeça limpo.
        DB::table($table)->whereNull('equipment_id')->delete();

        // Defensivo: o FK antigo (order_equipment_id, cascadeOnDelete) já deveria ter apagado
        // qualquer linha presa a uma OS editada, então duas linhas colidindo na chave nova não
        // deveriam existir — mas adicionar o unique direto quebraria a migration inteira no meio
        // se essa suposição estiver errada em algum ambiente real. Mantém uma linha arbitrária de
        // cada combinação (todas seriam o mesmo fato — "este marco já foi avisado" — repetido).
        // Tabela derivada (fromSub) porque o MySQL recusa DELETE com subquery direta na própria
        // tabela (erro 1093); o SQLite dos testes aceitava, por isso só apareceu no MySQL.
        DB::table($table)
            ->whereNotIn('id', function ($query) {
                $query->select('keep.id')->fromSub(
                    fn ($latest) => $latest->selectRaw('MAX(id) as id')
                        ->from('order_equipment_situation_alerts')
                        ->groupBy(['order_id', 'equipment_id', 'situation_changed_at', 'milestone_days']),
                    'keep',
                );
            })
            ->delete();

        $hasForeign = collect(Schema::getForeignKeys($table))->contains(fn ($fk) => $fk['columns'] === ['order_equipment_id']);
        $indexes = collect(Schema::getIndexes($table))->keyBy('name');
        $indexName = 'order_equipment_situation_alerts_unique_milestone';
        $uniqueIsNew = $indexes->has($indexName) && $indexes[$indexName]['columns'] === ['order_id', 'equipment_id', 'situation_changed_at', 'milestone_days'];

        // FK antes do índice: no MySQL o unique antigo (order_equipment_id, ...) é o índice que
        // sustenta a FK, e dropar o índice primeiro dá erro 1553.
        Schema::table($table, function (Blueprint $blueprint) use ($table, $hasForeign, $indexes, $indexName, $uniqueIsNew) {
            if ($hasForeign) {
                $blueprint->dropForeign(['order_equipment_id']);
            }
            if ($indexes->has($indexName) && ! $uniqueIsNew) {
                $blueprint->dropUnique($indexName);
            }
            if (Schema::hasColumn($table, 'order_equipment_id')) {
                $blueprint->dropColumn('order_equipment_id');
            }
            if (! $uniqueIsNew) {
                $blueprint->unique(['order_id', 'equipment_id', 'situation_changed_at', 'milestone_days'], $indexName);
            }
        });
    }

    public function down(): void
    {
        // order_equipment_id nasce nullable aqui (a criação original era NOT NULL) — sem
        // doctrine/dbal instalado, apertar pra NOT NULL depois do backfill abaixo exigiria
        // recriar a tabela inteira. Rollback é best-effort, não um caminho usado em operação.
        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->foreignUuid('order_equipment_id')->nullable()->constrained('order_equipments')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE order_equipment_situation_alerts
            SET order_equipment_id = (SELECT id FROM order_equipments WHERE order_equipments.order_id = order_equipment_situation_alerts.order_id AND order_equipments.equipment_id = order_equipment_situation_alerts.equipment_id)
        ');

        Schema::table('order_equipment_situation_alerts', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropForeign(['equipment_id']);
            $table->dropUnique('order_equipment_situation_alerts_unique_milestone');
            $table->dropColumn(['order_id', 'equipment_id']);
            $table->unique(
                ['order_equipment_id', 'situation_changed_at', 'milestone_days'],
                'order_equipment_situation_alerts_unique_milestone',
            );
        });
    }
};
