<?php

namespace App\Modules\Equipments\Infrastructure\Projectors;

use App\Modules\Equipments\Domain\Events\EquipmentRegistered;
use App\Modules\Equipments\Domain\Events\EquipmentRemoved;
use App\Modules\Equipments\Domain\Events\EquipmentUpdated;
use App\Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class EquipmentProjector extends Projector
{
    public function onEquipmentRegistered(EquipmentRegistered $event): void
    {
        Equipment::create([
            'id' => $event->aggregateRootUuid(),
            'client_id' => $event->clientId,
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
            'accessories' => $event->accessories,
        ]);
    }

    public function onEquipmentUpdated(EquipmentUpdated $event): void
    {
        Equipment::whereKey($event->aggregateRootUuid())->update([
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
            'accessories' => $event->accessories,
        ]);
    }

    public function onEquipmentRemoved(EquipmentRemoved $event): void
    {
        Equipment::whereKey($event->aggregateRootUuid())->delete();
    }
}
