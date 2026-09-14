<?php

namespace Modules\Accessories\Infrastructure\Projectors;

use Modules\Accessories\Domain\Events\AccessoryRegistered;
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
}
