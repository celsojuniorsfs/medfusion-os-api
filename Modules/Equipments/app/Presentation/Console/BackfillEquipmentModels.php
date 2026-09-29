<?php

namespace Modules\Equipments\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\EquipmentModels\Application\RegisterEquipmentModel;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;

/**
 * Comando de uma vez só, rodado depois do deploy do api#101: semeia o catálogo global com o que já
 * existe no banco e liga os equipamentos já cadastrados às entradas correspondentes.
 *
 * Mora em Equipments, não em EquipmentModels, pela direção do grafo de dependências (ver
 * CLAUDE.md): Equipments pode chamar o catálogo, nunca o contrário.
 *
 * Cadastrar entrada nova passa pela Action de verdade (agregado, evento, projector) — nada de
 * INSERT cru, ou a linha some no primeiro replay. Já preencher `equipments.equipment_model_id` é
 * UPDATE direto sem evento, e isso é correto: é campo derivado, e o UPDATE só antecipa o que o
 * EquipmentProjector já produziria reprocessando um evento antigo.
 *
 * Idempotente: só considera trios sem entrada no catálogo, e só preenche equipamentos com a FK
 * vazia.
 */
class BackfillEquipmentModels extends Command
{
    protected $signature = 'equipment-models:backfill {--dry-run : Só mostra o que faria, sem gravar}';

    protected $description = 'Semeia o catálogo global de modelos a partir dos equipamentos já cadastrados e liga um ao outro';

    public function handle(RegisterEquipmentModel $registerEquipmentModel): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $triples = DB::table('equipments')
            ->select('name', 'brand', 'model')
            ->whereNull('equipment_model_id')
            ->distinct()
            ->get();

        if ($triples->isEmpty()) {
            $this->info('Nenhum equipamento sem modelo — nada a fazer.');

            return self::SUCCESS;
        }

        $created = 0;
        $linked = 0;

        foreach ($triples as $triple) {
            $existing = EquipmentModel::where('name', $triple->name)
                ->where(fn ($query) => $this->matchNullable($query, 'brand', $triple->brand))
                ->where(fn ($query) => $this->matchNullable($query, 'model', $triple->model))
                ->value('id');

            if ($dryRun) {
                $this->line(sprintf(
                    '%s / %s / %s — %s',
                    $triple->name,
                    $triple->brand ?? '(sem marca)',
                    $triple->model ?? '(sem modelo)',
                    $existing ? 'já existe no catálogo, só liga' : 'cadastra no catálogo',
                ));

                continue;
            }

            if ($existing === null) {
                $existing = $registerEquipmentModel($triple->name, $triple->brand, $triple->model)->id;
                $created++;
            }

            // whereNull na FK mantém o comando idempotente e evita reescrever um vínculo que
            // alguém já tenha escolhido à mão depois do deploy.
            $linked += DB::table('equipments')
                ->where('name', $triple->name)
                ->where(fn ($query) => $this->matchNullable($query, 'brand', $triple->brand))
                ->where(fn ($query) => $this->matchNullable($query, 'model', $triple->model))
                ->whereNull('equipment_model_id')
                ->update(['equipment_model_id' => $existing]);
        }

        if ($dryRun) {
            $this->info(sprintf('%d trio(s) distinto(s) encontrado(s). Nada gravado (--dry-run).', $triples->count()));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d modelo(s) cadastrado(s) no catálogo, %d equipamento(s) ligado(s).', $created, $linked));

        return self::SUCCESS;
    }

    /**
     * `where('brand', null)` vira `brand = NULL` em SQL, que nunca é verdadeiro — a primeira versão
     * deste comando (api#101) tinha esse bug e simplesmente não ligou os equipamentos sem
     * marca/modelo, além de poder ter cadastrado entrada de catálogo que ficou órfã. Rodar de novo
     * com esta versão acha a entrada existente em vez de duplicar, e completa o vínculo.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function matchNullable($query, string $column, ?string $value)
    {
        return $value === null ? $query->whereNull($column) : $query->where($column, $value);
    }
}
