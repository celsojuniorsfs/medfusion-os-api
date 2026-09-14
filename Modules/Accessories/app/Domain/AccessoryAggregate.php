<?php

namespace Modules\Accessories\Domain;

use Modules\Accessories\Domain\Events\AccessoryRegistered;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de acessórios (api#92) — item de referência simples, sem workflow: cadastra
 * uma vez, reaproveita em qualquer equipamento de qualquer cliente daqui pra frente (mesmo
 * raciocínio de "peça de carro" da issue: o mesmo acessório se repete entre equipamentos
 * diferentes, só muda a quantidade). Sem update/remove nesta rodada — fora do pedido original.
 */
class AccessoryAggregate extends AggregateRoot
{
    public function register(string $name): self
    {
        $this->recordThat(new AccessoryRegistered($name));

        return $this;
    }

    protected function applyAccessoryRegistered(AccessoryRegistered $event): void {}
}
