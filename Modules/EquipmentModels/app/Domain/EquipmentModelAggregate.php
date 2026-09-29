<?php

namespace Modules\EquipmentModels\Domain;

use Modules\EquipmentModels\Domain\Events\EquipmentModelRegistered;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRemoved;
use Modules\EquipmentModels\Domain\Events\EquipmentModelUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de modelos de equipamento (marca/modelo reutilizável entre clientes). `equipments`
 * guarda uma cópia de nome/marca/modelo, então editar uma entrada aqui precisa alcançar essa cópia —
 * quem faz isso é o EquipmentProjector, do lado de Equipments, reagindo a EquipmentModelUpdated.
 */
class EquipmentModelAggregate extends AggregateRoot
{
    private bool $removed = false;

    // O agregado guarda o trio atual para poder informar o ANTERIOR ao renomear — ver
    // EquipmentModelUpdated para o motivo.
    private string $name = '';

    private ?string $brand = null;

    private ?string $model = null;

    public function register(string $name, ?string $brand, ?string $model): self
    {
        $this->recordThat(new EquipmentModelRegistered($name, $brand, $model));

        return $this;
    }

    public function update(string $name, ?string $brand, ?string $model): self
    {
        $this->recordThat(new EquipmentModelUpdated(
            $name, $brand, $model, $this->name, $this->brand, $this->model,
        ));

        return $this;
    }

    public function remove(): self
    {
        if (! $this->removed) {
            $this->recordThat(new EquipmentModelRemoved);
        }

        return $this;
    }

    protected function applyEquipmentModelRegistered(EquipmentModelRegistered $event): void
    {
        $this->name = $event->name;
        $this->brand = $event->brand;
        $this->model = $event->model;
    }

    protected function applyEquipmentModelUpdated(EquipmentModelUpdated $event): void
    {
        $this->name = $event->name;
        $this->brand = $event->brand;
        $this->model = $event->model;
    }

    protected function applyEquipmentModelRemoved(EquipmentModelRemoved $event): void
    {
        $this->removed = true;
    }
}
