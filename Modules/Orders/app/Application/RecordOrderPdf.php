<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class RecordOrderPdf
{
    public function __invoke(string $orderId, string $path, string $generatedAt): Order
    {
        OrderAggregate::retrieve($orderId)
            ->recordPdfGenerated($path, $generatedAt)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
