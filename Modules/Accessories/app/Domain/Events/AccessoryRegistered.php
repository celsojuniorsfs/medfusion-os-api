<?php

namespace Modules\Accessories\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AccessoryRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $name,
    ) {}
}
