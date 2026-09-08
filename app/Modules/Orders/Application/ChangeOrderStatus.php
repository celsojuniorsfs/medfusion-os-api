<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\Enums\OrderStatus;
use App\Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use App\Modules\Orders\Domain\OrderAggregate;
use App\Modules\Orders\Infrastructure\ReadModels\Order;

class ChangeOrderStatus
{
    /**
     * @throws InvalidOrderStatusTransition
     */
    public function __invoke(string $orderId, OrderStatus $to): Order
    {
        OrderAggregate::retrieve($orderId)
            ->changeStatus($to)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
