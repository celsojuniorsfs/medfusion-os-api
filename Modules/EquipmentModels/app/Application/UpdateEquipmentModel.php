<?php

namespace Modules\EquipmentModels\Application;

use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;

class UpdateEquipmentModel
{
    public function __invoke(string $id, string $name, ?string $brand, ?string $model): EquipmentModel
    {
        EquipmentModelAggregate::retrieve($id)
            ->update($name, $brand, $model)
            ->persist();

        return EquipmentModel::findOrFail($id);
    }
}
