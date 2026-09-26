<?php

namespace Modules\Orders\Application;

use Illuminate\Support\Str;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class AttachEquipmentToOrder
{
    /**
     * O uuid da linha order_equipments é gerado aqui, não no projector (mesmo motivo de
     * AddEquipmentPhoto::photoId): pra order_equipment_accessories referenciar um id
     * determinístico — sem isso, um replay geraria uma FK diferente a cada rodada.
     *
     * @param  array<int, array{name: string, quantity: int}>  $accessories
     */
    public function __invoke(
        string $orderId,
        ?string $equipmentId,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        array $accessories = [],
    ): Order {
        $orderEquipmentId = (string) Str::uuid();

        OrderAggregate::retrieve($orderId)
            ->attachEquipment($orderEquipmentId, $equipmentId, $name, $brand, $model, $serialNumber, $assetTag, $accessories)
            ->persist();

        return Order::findOrFail($orderId);
    }
}
