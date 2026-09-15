<?php

namespace Modules\EquipmentModels\Application;

use Illuminate\Support\Str;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;

class RegisterEquipmentModel
{
    public function __invoke(string $name, ?string $brand, ?string $model): EquipmentModel
    {
        $uuid = (string) Str::uuid();

        EquipmentModelAggregate::retrieve($uuid)
            ->register($name, $brand, $model)
            ->persist();

        return EquipmentModel::findOrFail($uuid);
    }
}
