<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderAggregate;
use App\Modules\Orders\Infrastructure\ReadModels\Order;

class AddOrderItem
{
    public function __invoke(string $orderId, float $quantity, string $description, ?float $unitPrice = null): Order
    {
        OrderAggregate::retrieve($orderId)
            ->addItem($quantity, $description, $unitPrice)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
