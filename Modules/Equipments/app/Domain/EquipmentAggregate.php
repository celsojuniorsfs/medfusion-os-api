<?php

namespace Modules\Equipments\Domain;

use Modules\Equipments\Domain\Events\EquipmentRegistered;
use Modules\Equipments\Domain\Events\EquipmentRemoved;
use Modules\Equipments\Domain\Events\EquipmentUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * clientId referencia o ClientAggregate só pelo uuid — Equipments nunca importa nada de
 * Modules\Clients\Domain (regra de fronteira entre módulos, ver architecture.md).
 */
class EquipmentAggregate extends AggregateRoot
{
    private bool $removed = false;

    public function register(
        string $clientId,
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        ?string $accessories,
    ): self {
        $this->recordThat(new EquipmentRegistered(
            $clientId, $name, $brand, $model, $serialNumber, $assetTag, $accessories,
        ));

        return $this;
    }

    public function update(
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        ?string $accessories,
    ): self {
        $this->recordThat(new EquipmentUpdated(
            $name, $brand, $model, $serialNumber, $assetTag, $accessories,
        ));

        return $this;
    }

    public function remove(): self
    {
        if (! $this->removed) {
            $this->recordThat(new EquipmentRemoved);
        }

        return $this;
    }

    protected function applyEquipmentRegistered(EquipmentRegistered $event): void {}

    protected function applyEquipmentUpdated(EquipmentUpdated $event): void {}

    protected function applyEquipmentRemoved(EquipmentRemoved $event): void
    {
        $this->removed = true;
    }
}
