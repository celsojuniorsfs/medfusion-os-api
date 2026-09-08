<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderStatusChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {}
}
