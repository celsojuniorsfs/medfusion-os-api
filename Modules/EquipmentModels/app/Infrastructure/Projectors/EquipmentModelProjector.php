<?php

namespace Modules\EquipmentModels\Infrastructure\Projectors;

use Modules\EquipmentModels\Domain\Events\EquipmentModelRegistered;
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

    // Sem Cache::increment aqui, ao contrário dos outros projectors (ver docs/architecture.md
    // § Cache): a listagem deste catálogo não é cacheada, e o catálogo é append-only — não existe
    // edição que pudesse deixar desatualizado o snapshot de nome/marca/modelo que `equipments`
    // guarda. ATENÇÃO: se um dia entrar um EquipmentModelUpdated, ele vai precisar invalidar a
    // listagem de equipamentos também (Cache::increment('equipments:cache-version')), senão a
    // correção de um nome de modelo não aparece nas listagens já cacheadas.
}
