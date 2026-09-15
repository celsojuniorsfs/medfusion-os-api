<?php

namespace Modules\EquipmentModels\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentModelRegistered extends ShouldBeStored
{
    /**
     * brand/model são nullable aqui (e não na validação de entrada, que exige os três) por causa
     * do backfill: equipamentos cadastrados antes do api#92 podem ter ficado sem marca/modelo, e
     * o catálogo precisa conseguir representá-los.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
    ) {}
}
