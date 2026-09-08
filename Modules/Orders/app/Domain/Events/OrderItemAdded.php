<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderItemAdded extends ShouldBeStored
{
    public function __construct(
        public readonly float $quantity,
        public readonly string $description,
        public readonly ?float $unitPrice,
    ) {}
}
