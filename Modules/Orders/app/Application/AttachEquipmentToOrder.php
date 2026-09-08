<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class AttachEquipmentToOrder
{
    public function __invoke(
        string $orderId,
        ?string $equipmentId,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        ?string $accessories = null,
    ): Order {
        OrderAggregate::retrieve($orderId)
            ->attachEquipment($equipmentId, $name, $brand, $model, $serialNumber, $assetTag, $accessories)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
