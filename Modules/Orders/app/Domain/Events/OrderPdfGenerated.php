<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderPdfGenerated extends ShouldBeStored
{
    public function __construct(
        public readonly string $path,
        public readonly string $generatedAt,
    ) {}
}
