<?php

namespace Modules\Accessories\Infrastructure\Projectors;

use Illuminate\Support\Facades\Schema;
use Modules\Accessories\Domain\Events\AccessoryRegistered;
use Modules\Accessories\Domain\Events\AccessoryRemoved;
use Modules\Accessories\Domain\Events\AccessoryUpdated;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AccessoryProjector extends Projector
{
    public function onAccessoryRegistered(AccessoryRegistered $event): void
    {
        Accessory::create([
            'id' => $event->aggregateRootUuid(),
            'name' => $event->name,
        ]);
    }

    public function onAccessoryUpdated(AccessoryUpdated $event): void
    {
        Accessory::whereKey($event->aggregateRootUuid())->update(['name' => $event->name]);
    }

    public function onAccessoryRemoved(AccessoryRemoved $event): void
    {
        Accessory::whereKey($event->aggregateRootUuid())->delete();
    }

    // Sem Cache::increment aqui: a listagem deste catálogo não é cacheada, e a de equipamentos —
    // que embute o nome do acessório — pertence a Equipments, módulo acima deste no grafo. Quem
    // invalida é o EquipmentProjector, reagindo a estes mesmos eventos (ver architecture.md § Cache).

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0` (ver Projectionist::replay).
     * FKs desligadas: `equipment_accessories.accessory_id` (restrictOnDelete) pertence a
     * Equipments, e replayar só este projector não pode falhar por causa de outro módulo.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(fn () => Accessory::query()->delete());
    }
}
