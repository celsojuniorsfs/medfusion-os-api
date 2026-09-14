<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRegistered extends ShouldBeStored
{
    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories  já resolvido
     *                                                                               — accessory_id sempre presente (nome novo já foi cadastrado no catálogo antes do
     *                                                                               evento ser gravado, ver EquipmentController)
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        public readonly array $accessories,
    ) {}
}
