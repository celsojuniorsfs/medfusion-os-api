<?php

namespace Modules\Accessories\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Atravessa a fronteira do módulo: o EquipmentProjector (de Equipments) reage a ele só pra
 * invalidar o cache da listagem de equipamentos, que embute o nome do acessório.
 */
class AccessoryUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
    ) {}
}
