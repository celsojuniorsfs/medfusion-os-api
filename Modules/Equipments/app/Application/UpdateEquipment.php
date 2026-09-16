<?php

namespace Modules\Equipments\Application;

use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

class UpdateEquipment
{
    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories  já resolvido
     *                                                                               pela Presentation (ver EquipmentController)
     */
    public function __invoke(
        string $id,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        array $accessories = [],
        ?string $equipmentModelId = null,
    ): Equipment {
        EquipmentAggregate::retrieve($id)
            ->update($name, $brand, $model, $serialNumber, $assetTag, $accessories, $equipmentModelId)
            ->persist();

        return Equipment::findOrFail($id);
    }
}
