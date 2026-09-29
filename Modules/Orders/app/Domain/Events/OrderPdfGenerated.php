<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderPdfGenerated extends ShouldBeStored
{
    /**
     * @param  ?list<string>  $orderEquipmentIds  ids incluídos (api#149); `null` = todos.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $generatedAt,
        public readonly ?array $orderEquipmentIds = null,
    ) {}
}
