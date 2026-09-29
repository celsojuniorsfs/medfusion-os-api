<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use Modules\Orders\Domain\Exceptions\OrderHasPendingEquipments;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class ChangeOrderStatus
{
    public function __construct(private readonly DeriveOrderStatusFromEquipments $deriveOrderStatus) {}

    /**
     * @throws InvalidOrderStatusTransition
     * @throws OrderHasPendingEquipments quando `$to` é `completed` com algum equipamento não
     *                                   resolvido (api#140) — a transição automática pra
     *                                   `completed` (ver OrderStatus::derivedFromEquipments())
     *                                   só ocorre quando todos já estão, então esta checagem só
     *                                   pega a tentativa MANUAL de pular a etapa.
     */
    public function __invoke(string $orderId, OrderStatus $to): Order
    {
        if ($to === OrderStatus::Completed) {
            $order = Order::with('equipments')->findOrFail($orderId);

            $hasPending = $order->equipments->contains(
                fn ($equipment) => ! OrderEquipmentSituation::from($equipment->situation)->isResolved(),
            );

            if ($hasPending) {
                throw new OrderHasPendingEquipments;
            }
        }

        OrderAggregate::retrieve($orderId)
            ->changeStatus($to)
            ->persist();

        // Achado em code review: um técnico pode marcar todos os equipamentos como resolvidos
        // ANTES de aprovar a OS (a situação é independente do status, ver S2) — sem isto, a OS
        // ficaria presa em `approved` até alguém mexer de novo na situação de algum equipamento
        // e disparar a derivação por outro caminho. Só em `approved`, não em `warranty_repair`:
        // reabrir em garantia é sempre um fluxo em duas etapas (reabre a OS, DEPOIS marca o
        // equipamento do retrabalho como pendente) — os outros equipamentos continuam
        // `completed` nesse meio-tempo de propósito, e derivar aqui devolveria a OS pra
        // `completed` sozinha antes do segundo passo acontecer.
        if ($to === OrderStatus::Approved) {
            $this->deriveOrderStatus->__invoke($orderId);
        }

        return Order::findOrFail($orderId);
    }
}
