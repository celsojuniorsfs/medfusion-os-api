<?php

namespace Modules\Accessories\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccessoryRemoved extends ShouldBeStored
{
    public function __construct() {}
}
