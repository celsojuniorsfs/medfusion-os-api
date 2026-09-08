<?php

namespace App\Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRegistered extends ShouldBeStored
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        public readonly ?string $accessories,
    ) {}
}
