<?php

namespace Modules\Accessories\Domain;

use Modules\Accessories\Domain\Events\AccessoryRegistered;
use Modules\Accessories\Domain\Events\AccessoryRemoved;
use Modules\Accessories\Domain\Events\AccessoryUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de acessórios (api#92) — item de referência simples, sem workflow: cadastra
 * uma vez, reaproveita em qualquer equipamento de qualquer cliente daqui pra frente (mesmo
 * raciocínio de "peça de carro" da issue: o mesmo acessório se repete entre equipamentos
 * diferentes, só muda a quantidade).
 *
 * Ganhou update/remove no api#109, pelo mesmo motivo do catálogo de modelos: as entradas nascem
 * sozinhas quando o técnico digita um acessório novo no formulário, então erro de digitação e dado
 * de teste ficavam no catálogo global pra sempre, sem nenhuma tela pra limpar.
 *
 * Ao contrário de EquipmentModel, renomear aqui **não** precisa propagar nada: o nome do acessório
 * não é copiado em lugar nenhum — `equipment_accessories` guarda só o id, e o nome vem pela relação
 * (ver EquipmentResource). O que precisa acontecer é invalidar a listagem de equipamentos, que
 * embute esse nome no cache; quem faz isso é o EquipmentProjector.
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
