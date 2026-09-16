<?php

namespace Modules\Accessories\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Atravessa a fronteira do módulo: o EquipmentProjector (de Equipments) reage a ele só pra invalidar
 * a listagem de equipamentos, que embute o nome do acessório no cache. Não há dado a propagar — o
 * nome vem pela relação, não é copiado.
 */
class AccessoryUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
    ) {}
}
