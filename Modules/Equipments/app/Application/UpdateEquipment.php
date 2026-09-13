<?php

namespace Modules\Equipments\Application;

use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

class UpdateEquipment
{
    public function __invoke(
        string $id,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        ?string $accessories = null,
    ): Equipment {
        EquipmentAggregate::retrieve($id)
            ->update($name, $brand, $model, $serialNumber, $assetTag, $accessories)
            ->persist();

        return Equipment::findOrFail($id);
    }
}
