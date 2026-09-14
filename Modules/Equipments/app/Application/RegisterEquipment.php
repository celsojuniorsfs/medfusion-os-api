<?php

namespace Modules\Equipments\Application;

use Illuminate\Support\Str;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

class RegisterEquipment
{
    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories  já
     *                                                                               resolvido pela Presentation (ver EquipmentController) — vazio é uma opção válida
     *                                                                               (ex.: equipamento novo cadastrado implicitamente por uma OS, que não coleta
     *                                                                               acessórios estruturados)
     */
    public function __invoke(
        string $clientId,
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $serialNumber = null,
        ?string $assetTag = null,
        array $accessories = [],
    ): Equipment {
        $uuid = (string) Str::uuid();

        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, $name, $brand, $model, $serialNumber, $assetTag, $accessories)
            ->persist();

        return Equipment::findOrFail($uuid);
    }
}
