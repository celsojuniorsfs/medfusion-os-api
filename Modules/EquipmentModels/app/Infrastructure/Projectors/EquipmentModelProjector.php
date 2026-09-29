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

    // Este catálogo não é cacheado (sem Cache::increment, diferente dos outros projectors — ver
    // docs/architecture.md § Cache). A listagem de EQUIPAMENTOS é cacheada e embute o nome/marca/
    // modelo copiado daqui; invalidar essa cache é responsabilidade do EquipmentProjector (em
    // Equipments), reagindo a EquipmentModelUpdated/Removed — este módulo não conhece aquele.

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0`. FKs desligadas porque
     * `equipments.equipment_model_id` (restrictOnDelete) pertence a outro módulo.
     *
     * $aggregateUuid vem preenchido em replay de um agregado só; a tabela inteira só é zerada
     * quando o replay é de fato completo ($aggregateUuid === null).
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            EquipmentModel::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });
    }
}
