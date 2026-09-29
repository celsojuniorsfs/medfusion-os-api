<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class AddOrderItem
{
    public function __invoke(
        string $orderId,
        float $quantity,
        string $description,
        ?float $unitPrice = null,
        ?string $orderEquipmentId = null,
    ): Order {
        OrderAggregate::retrieve($orderId)
            ->addItem($quantity, $description, $unitPrice, $orderEquipmentId)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
