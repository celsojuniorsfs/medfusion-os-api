<?php

namespace Modules\Equipments\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Modules\Equipments\Domain\Events\EquipmentUpdated;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
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

        $this->forgetCache();
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

        $this->forgetCache();
    }

    public function onEquipmentRemoved(EquipmentRemoved $event): void
    {
        Equipment::whereKey($event->aggregateRootUuid())->delete();

        $this->forgetCache();
    }

    /**
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) — uma tag só pro módulo
     * inteiro, não por cliente: EquipmentUpdated/EquipmentRemoved nem carregam client_id no
     * evento, e listas por cliente são pequenas o bastante pra um cache miss a mais em clientes
     * não afetados não pesar.
     */
    private function forgetCache(): void
    {
        Cache::tags(['equipments'])->flush();
    }
}
