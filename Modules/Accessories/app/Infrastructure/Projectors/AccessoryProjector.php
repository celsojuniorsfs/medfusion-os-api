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

    // Sem Cache::increment aqui: esta listagem não é cacheada. A de equipamentos (que embute o
    // nome do acessório) é invalidada pelo EquipmentProjector, reagindo a estes mesmos eventos.

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay` (ver Projectionist::replay). FKs
     * desligadas porque `equipment_accessories.accessory_id` pertence a Equipments, e replayar
     * só este projector não pode falhar por causa de outro módulo.
     *
     * Com `--aggregate-uuid=X`, $aggregateUuid vem preenchido e só aquela linha é apagada; num
     * replay completo ($aggregateUuid === null), a tabela inteira é zerada.
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            Accessory::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });
    }
}
