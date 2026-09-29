<?php

namespace Modules\Orders\Application;

use Illuminate\Support\Facades\DB;
use Modules\Orders\Domain\Enums\OrderEquipmentApprovalStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * PATCH /orders/{id}/equipments/approval (api#149) — aceita um id ou vários (mesmo padrão de
 * `ChangeOrderEquipmentSituation`). O FormRequest já garante que os ids pertencem à OS E já têm
 * `approval_status` não nulo (não dá pra aprovar/reprovar um orçamento que nunca foi gerado).
 */
class ChangeOrderEquipmentApprovalStatus
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly DeriveOrderStatusFromApprovals $deriveOrderStatus,
    ) {}

    /**
     * @param  array<int, string>  $orderEquipmentIds
     */
    public function __invoke(string $orderId, array $orderEquipmentIds, OrderEquipmentApprovalStatus $to): Order
    {
        // Mesma transação que ChangeOrderEquipmentSituation, mesmo motivo.
        DB::transaction(function () use ($orderId, $orderEquipmentIds, $to) {
            $order = Order::with('equipments')->findOrFail($orderId);

            $this->orderService->assertIsEditable($order);

            $approvalStatusByEquipmentId = $order->equipments->keyBy('id');

            $aggregate = OrderAggregate::retrieve($orderId);

            foreach (array_unique($orderEquipmentIds) as $orderEquipmentId) {
                $from = OrderEquipmentApprovalStatus::from($approvalStatusByEquipmentId[$orderEquipmentId]->approval_status);

                if ($from !== $to) {
                    $aggregate->changeEquipmentApprovalStatus($orderEquipmentId, $from, $to);
                }
            }

            $aggregate->persist();

            ($this->deriveOrderStatus)($orderId);
        });

        return Order::findOrFail($orderId);
    }
}
