<?php

namespace Modules\Accessories\Domain;

use Modules\Accessories\Domain\Events\AccessoryRegistered;
use Modules\Accessories\Domain\Events\AccessoryRemoved;
use Modules\Accessories\Domain\Events\AccessoryUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de acessórios: cadastra uma vez, reaproveita em qualquer equipamento.
 *
 * Ao contrário de EquipmentModel, renomear aqui não precisa propagar nada — o nome não é
 * copiado em lugar nenhum (`equipment_accessories` guarda só o id, o nome vem pela relação).
 * Só é preciso invalidar o cache da listagem de equipamentos, o que o EquipmentProjector faz.
 */
class AccessoryAggregate extends AggregateRoot
{
    private bool $removed = false;

    public function register(string $name): self
    {
        $this->recordThat(new AccessoryRegistered($name));

        return $this;
    }

    public function update(string $name): self
    {
        $this->recordThat(new AccessoryUpdated($name));

        return $this;
    }

    public function remove(): self
    {
        if (! $this->removed) {
            $this->recordThat(new AccessoryRemoved);
        }

        return $this;
    }

    protected function applyAccessoryRegistered(AccessoryRegistered $event): void {}

    protected function applyAccessoryUpdated(AccessoryUpdated $event): void {}

    protected function applyAccessoryRemoved(AccessoryRemoved $event): void
    {
        $this->removed = true;
    }
}
