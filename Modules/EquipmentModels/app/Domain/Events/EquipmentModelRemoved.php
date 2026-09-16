<?php

namespace Modules\EquipmentModels\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentModelRemoved extends ShouldBeStored
{
    public function __construct() {}
}
