<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

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
