<?php

namespace Modules\EquipmentModels\Domain;

use Modules\EquipmentModels\Domain\Events\EquipmentModelRegistered;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de modelos de equipamento (api#101) — item de referência simples, sem workflow:
 * cadastra "Ultrassom / Sonopus / XYZ-100" uma vez e reaproveita em qualquer cliente daqui pra
 * frente. Mesma ideia do catálogo de acessórios (AccessoryAggregate), agora pro próprio aparelho:
 * o pedido do cliente era parar de redigitar nome/marca/modelo a cada unidade física, deixando só
 * o que é dela (número de série, patrimônio, acessórios).
 *
 * Sem update/remove nesta rodada — fora do pedido, e a ausência de edição é o que garante que o
 * snapshot em `equipments` nunca fica desatualizado (ver EquipmentModelProjector).
 */
class EquipmentModelAggregate extends AggregateRoot
{
    public function register(string $name, ?string $brand, ?string $model): self
    {
        $this->recordThat(new EquipmentModelRegistered($name, $brand, $model));

        return $this;
    }

    protected function applyEquipmentModelRegistered(EquipmentModelRegistered $event): void {}
}
