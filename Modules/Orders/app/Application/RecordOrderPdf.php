<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderEquipmentApprovalStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class RecordOrderPdf
{
    /**
     * @param  ?list<string>  $orderEquipmentIds  api#149 — null = orçamento da OS inteira (todos
     *                                            os equipamentos).
     */
    public function __invoke(string $orderId, string $path, string $generatedAt, ?array $orderEquipmentIds = null): Order
    {
        $order = Order::with('equipments')->findOrFail($orderId);

        $aggregate = OrderAggregate::retrieve($orderId)->recordPdfGenerated($path, $generatedAt, $orderEquipmentIds);

        $includedEquipments = $orderEquipmentIds === null
            ? $order->equipments
            : $order->equipments->whereIn('id', $orderEquipmentIds);

        // Só ativa o acompanhamento (null → awaiting_approval) pra quem ainda não tinha nenhum —
        // gerar de novo não reseta uma aprovação/reprovação já dada.
        foreach ($includedEquipments as $equipment) {
            if ($equipment->approval_status === null) {
                $aggregate->changeEquipmentApprovalStatus($equipment->id, null, OrderEquipmentApprovalStatus::AwaitingApproval);
            }
        }

        $aggregate->persist();

        return Order::findOrFail($orderId);
    }
}
