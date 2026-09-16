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
 * É o que entrega o pedido do cliente ("cadastrar todos os equipamentos que a gente já atuou na
 * assistência") sem ninguém redigitar nada: o catálogo nasce com o histórico dele dentro.
 *
 * Mora em Equipments, não em EquipmentModels, por causa da direção do grafo de dependências (ver
 * CLAUDE.md): Equipments pode ler/chamar o catálogo — é o que o EquipmentController já faz —, mas
 * o catálogo não pode conhecer quem está acima dele.
 *
 * Duas etapas, com naturezas diferentes de propósito:
 *
 * 1. Cadastrar as entradas de catálogo passa pela Action de verdade — agregado, evento gravado,
 *    projector. Nada de INSERT cru: uma linha de catálogo sem evento sumiria no primeiro
 *    `event-sourcing:replay`.
 * 2. Preencher `equipments.equipment_model_id` é um UPDATE direto, sem evento — e isso está certo,
 *    não é atalho: essa coluna é campo derivado de projeção, e o EquipmentProjector já reproduz
 *    exatamente essa mesma derivação (busca pelo trio) ao reprocessar um evento antigo. O UPDATE
 *    só antecipa o que um replay produziria; não é uma decisão de domínio nova.
 *
 * Idempotente: rodar duas vezes não duplica nada (só considera trios que ainda não têm entrada no
 * catálogo, e só preenche equipamentos com a FK vazia).
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
                ->where('brand', $triple->brand)
                ->where('model', $triple->model)
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
                ->where('brand', $triple->brand)
                ->where('model', $triple->model)
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
}
