<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class OrderItemAdded extends ShouldBeStored
{
    public function __construct(
        public readonly float $quantity,
        public readonly string $description,
        public readonly ?float $unitPrice,
        // api#149 — null = item geral, sem equipamento específico (também o significado correto
        // pra eventos gravados antes da #149, que nunca tiveram este conceito).
        public readonly ?string $orderEquipmentId = null,
    ) {}
}
