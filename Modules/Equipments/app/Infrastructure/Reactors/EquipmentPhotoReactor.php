<?php

namespace Modules\Equipments\Infrastructure\Reactors;

use Illuminate\Support\Facades\Storage;
use Modules\Equipments\Domain\Events\EquipmentPhotoRemoved;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Primeiro Reactor do repositório. Apaga o arquivo da foto do disco — e a razão de ser um Reactor,
 * e não mais um handler no EquipmentProjector, é uma só: **Projector roda de novo a cada
 * `event-sourcing:replay`; Reactor não.** Manter a deleção aqui é o que deixa o replay sem nenhum
 * efeito colateral fora do banco, e portanto seguro de rodar como operação de rotina.
 *
 * O risco concreto que isso evita não é "um replay apaga todas as fotos" (as deleções reexecutadas
 * apontam pra arquivos que em geral já sumiram): é o caso em que disco e banco não estão no mesmo
 * ponto no tempo — banco restaurado de backup, arquivos íntegros, replay pra reconstruir as
 * projeções. Com a deleção no projector, esse replay apagaria arquivos que deveriam continuar lá, e
 * o binário não está no event store pra ser recuperado.
 *
 * Síncrono, e isso é um desvio consciente do "Reactors em fila" documentado em architecture.md:
 * não roda worker de fila em produção hoje (ver docs/ambientes.md — o serviço de fila local está
 * atrás do profile `extra` e nunca foi ligado de verdade), então um job enfileirado ficaria
 * parado pra sempre e o arquivo nunca seria apagado. Apagar um arquivo é rápido, e a resposta HTTP
 * aguentar isso é preferível a vazar arquivo órfão no Object Storage. Quando a fila passar a rodar
 * de verdade em produção, vale mover pra `ShouldQueue` — a proteção contra replay, que é o motivo
 * de ser Reactor, não muda nos dois casos.
 */
class EquipmentPhotoReactor extends Reactor
{
    public function onEquipmentPhotoRemoved(EquipmentPhotoRemoved $event): void
    {
        $this->deletePhotos([$event->path]);
    }

    public function onEquipmentRemoved(EquipmentRemoved $event): void
    {
        // As linhas de equipment_photos já sumiram por cascade — os caminhos vêm do próprio evento
        // (ver EquipmentRemoved), lidos antes da remoção.
        $this->deletePhotos($event->photoPaths);
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function deletePhotos(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk(config('filesystems.default'))->delete($path);
        }
    }
}
