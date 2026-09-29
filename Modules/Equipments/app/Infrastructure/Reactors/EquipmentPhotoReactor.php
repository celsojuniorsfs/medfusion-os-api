<?php

namespace Modules\Equipments\Infrastructure\Reactors;

use Illuminate\Support\Facades\Storage;
use Modules\Equipments\Domain\Events\EquipmentPhotoRemoved;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Apaga o arquivo da foto do disco. É Reactor, não handler no EquipmentProjector, porque
 * **Projector roda de novo a cada `event-sourcing:replay`; Reactor não.** Isso importa quando
 * disco e banco não estão no mesmo ponto no tempo (ex.: banco restaurado de backup, replay pra
 * reconstruir projeções) — deleção no projector apagaria arquivos que deveriam continuar lá, sem
 * o binário no event store pra recuperar.
 *
 * Síncrono por desvio consciente do "Reactors em fila" de architecture.md: não há worker de fila
 * rodando em produção hoje (ver docs/ambientes.md), então um job enfileirado nunca seria
 * processado. Quando a fila passar a rodar de verdade, mover pra `ShouldQueue` — a proteção contra
 * replay, que é o motivo de ser Reactor, não muda.
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
