<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * PATCH /orders/{id}/equipments/situation (api#140) — um id ou vários (marcação em lote, pedida
 * pelo cliente na S2 pra lotes de prefeitura/hospital que chegam a 60 equipamentos): mesmo
 * endpoint serve os dois casos.
 */
class ChangeOrderEquipmentSituation
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly DeriveOrderStatusFromEquipments $deriveOrderStatus,
    ) {}

    /**
     * @param  array<int, string>  $orderEquipmentIds  já validados como pertencentes à OS pelo
     *                                                 FormRequest (Rule::exists com where
     *                                                 order_id) — confia nisso aqui, mesmo
     *                                                 padrão do resto do módulo.
     */
    public function __invoke(string $orderId, array $orderEquipmentIds, OrderEquipmentSituation $to): Order
    {
        $order = Order::with('equipments')->findOrFail($orderId);

        // canceled/not_approved/completed: mesma trava de PUT (OrderService::assertIsEditable) —
        // em completed, o caminho é reabrir em garantia primeiro (PATCH /status).
        $this->orderService->assertIsEditable($order);

        $situationByEquipmentId = $order->equipments->keyBy('id');

        $aggregate = OrderAggregate::retrieve($orderId);

        foreach ($orderEquipmentIds as $orderEquipmentId) {
            $from = OrderEquipmentSituation::from($situationByEquipmentId[$orderEquipmentId]->situation);

            if ($from !== $to) {
                $aggregate->changeEquipmentSituation($orderEquipmentId, $from, $to);
            }
        }

        $aggregate->persist();

        // Depois de persistir — o read model já reflete as situações novas, e a derivação lê de
        // lá (ver DeriveOrderStatusFromEquipments), não do que calculamos aqui em memória.
        ($this->deriveOrderStatus)($orderId);

        return Order::findOrFail($orderId);
    }
}
