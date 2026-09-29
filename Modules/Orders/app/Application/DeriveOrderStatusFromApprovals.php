<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderEquipmentApprovalStatus;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * Mesmo padrão de `DeriveOrderStatusFromEquipments`, pro orçamento por equipamento (api#149).
 */
class DeriveOrderStatusFromApprovals
{
    public function __invoke(string $orderId): void
    {
        $order = Order::with('equipments')->findOrFail($orderId);

        $approvalStatuses = $order->equipments
            ->pluck('approval_status')
            ->filter()
            ->map(fn (string $status) => OrderEquipmentApprovalStatus::from($status))
            ->all();

        $derived = OrderStatus::from($order->status)->derivedFromApprovals($approvalStatuses);

        if ($derived !== null) {
            OrderAggregate::retrieve($orderId)->changeStatus($derived, automatic: true)->persist();
        }
    }
}
