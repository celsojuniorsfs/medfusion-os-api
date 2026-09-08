<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRemoved extends ShouldBeStored
{
    public function __construct() {}
}
