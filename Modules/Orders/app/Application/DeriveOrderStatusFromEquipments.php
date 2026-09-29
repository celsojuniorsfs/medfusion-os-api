<?php

namespace Modules\Orders\Application;

use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * Reaplica `OrderStatus::derivedFromEquipments()` (api#140) sobre o estado ATUAL dos
 * equipamentos no read model — chamada depois de qualquer operação que possa ter mudado quantos
 * equipamentos estão resolvidos sem passar por `ChangeOrderEquipmentSituation` (que já deriva
 * sozinho): hoje, só `UpdateOrder`, porque reanexar equipamentos numa edição de OS (`clearEquipments()`
 * + `attachEquipment()` de novo) pode adicionar/remover equipamentos concluídos e deixar o status
 * "Parcialmente concluída" desatualizado. Sem efeito (`$derived === null`) na maioria das edições.
 */
class DeriveOrderStatusFromEquipments
{
    public function __invoke(string $orderId): void
    {
        $order = Order::with('equipments')->findOrFail($orderId);

        $situations = $order->equipments
            ->map(fn ($equipment) => OrderEquipmentSituation::from($equipment->situation))
            ->all();

        $derived = OrderStatus::from($order->status)->derivedFromEquipments($situations);

        if ($derived !== null) {
            OrderAggregate::retrieve($orderId)->changeStatus($derived, automatic: true)->persist();
        }
    }
}
