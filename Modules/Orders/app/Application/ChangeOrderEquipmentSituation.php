<?php

namespace Modules\Orders\Application;

use Illuminate\Support\Facades\DB;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * PATCH /orders/{id}/equipments/situation (api#140) — aceita um id ou vários (marcação em lote):
 * mesmo endpoint serve os dois casos.
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
        // Numa transação só (mesmo motivo de OrderController::update()) — sem isto, uma falha
        // entre persistir os eventos de situação e derivar o status deixaria os equipamentos já
        // mudados mas a OS presa no status antigo.
        DB::transaction(function () use ($orderId, $orderEquipmentIds, $to) {
            $order = Order::with('equipments')->findOrFail($orderId);

            // canceled/not_approved/completed: mesma trava de PUT (OrderService::assertIsEditable)
            // — em completed, o caminho é reabrir em garantia primeiro (PATCH /status).
            $this->orderService->assertIsEditable($order);

            $situationByEquipmentId = $order->equipments->keyBy('id');

            $aggregate = OrderAggregate::retrieve($orderId);

            // array_unique: sem isto, um id repetido no lote gravaria dois
            // OrderEquipmentSituationChanged com o mesmo `from` obsoleto (lido antes do loop),
            // corrompendo o histórico mesmo com o estado final projetado correto.
            foreach (array_unique($orderEquipmentIds) as $orderEquipmentId) {
                $from = OrderEquipmentSituation::from($situationByEquipmentId[$orderEquipmentId]->situation);

                if ($from !== $to) {
                    $aggregate->changeEquipmentSituation($orderEquipmentId, $from, $to);
                }
            }

            $aggregate->persist();

            // Depois de persistir — o read model já reflete as situações novas, e a derivação lê
            // de lá (ver DeriveOrderStatusFromEquipments), não do que calculamos aqui em memória.
            ($this->deriveOrderStatus)($orderId);
        });

        return Order::findOrFail($orderId);
    }
}
