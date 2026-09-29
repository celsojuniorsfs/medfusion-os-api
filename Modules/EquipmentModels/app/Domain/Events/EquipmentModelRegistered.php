<?php

namespace Modules\EquipmentModels\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentModelRegistered extends ShouldBeStored
{
    /**
     * brand/model são nullable aqui (diferente da validação de entrada, que exige os três) para
     * representar equipamentos legados cadastrados sem marca/modelo.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
    ) {}
}
