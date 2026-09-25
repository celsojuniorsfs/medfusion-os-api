<?php

namespace Modules\EquipmentModels\Infrastructure\Projectors;

use Illuminate\Support\Facades\Schema;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRegistered;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRemoved;
use Modules\EquipmentModels\Domain\Events\EquipmentModelUpdated;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class EquipmentModelProjector extends Projector
{
    public function onEquipmentModelRegistered(EquipmentModelRegistered $event): void
    {
        EquipmentModel::create([
            'id' => $event->aggregateRootUuid(),
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
        ]);
    }

    public function onEquipmentModelUpdated(EquipmentModelUpdated $event): void
    {
        EquipmentModel::whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
        ]);
    }

    public function onEquipmentModelRemoved(EquipmentModelRemoved $event): void
    {
        EquipmentModel::whereKey($event->aggregateRootUuid())->delete();
    }

    // Sem Cache::increment aqui, ao contrário dos outros projectors (ver docs/architecture.md
    // § Cache): a listagem deste catálogo não é cacheada.
    //
    // Mas a listagem de EQUIPAMENTOS é, e ela embute o nome/marca/modelo copiado do catálogo — o
    // aviso que este comentário trazia desde o api#101 ("se um dia entrar um EquipmentModelUpdated,
    // vai precisar invalidar a listagem de equipamentos") virou realidade no api#109. Quem cuida
    // disso é o EquipmentProjector, reagindo a EquipmentModelUpdated/Removed do lado de Equipments:
    // a chave `equipments:cache-version` é de lá, e este módulo não pode conhecer aquele.

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0` (ver Projectionist::replay).
     * FKs desligadas: `equipments.equipment_model_id` (restrictOnDelete) pertence a Equipments, e
     * replayar só este projector não pode falhar por causa de outro módulo.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(fn () => EquipmentModel::query()->delete());
    }
}
