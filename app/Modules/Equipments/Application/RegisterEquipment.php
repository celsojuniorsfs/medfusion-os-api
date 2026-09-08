<?php

namespace App\Modules\Equipments\Application;

use App\Modules\Equipments\Domain\EquipmentAggregate;
use App\Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Illuminate\Support\Str;

class RegisterEquipment
{
    public function __invoke(
        string $clientId,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        ?string $accessories = null,
    ): Equipment {
        $uuid = (string) Str::uuid();

        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, $name, $brand, $model, $serialNumber, $assetTag, $accessories)
            ->persist();

        return Equipment::findOrFail($uuid);
    }
}
