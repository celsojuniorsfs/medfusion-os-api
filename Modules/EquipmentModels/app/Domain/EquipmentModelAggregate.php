<?php

namespace Modules\EquipmentModels\Domain;

use Modules\EquipmentModels\Domain\Events\EquipmentModelRegistered;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRemoved;
use Modules\EquipmentModels\Domain\Events\EquipmentModelUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Catálogo global de modelos de equipamento (api#101) — item de referência simples, sem workflow:
 * cadastra "Ultrassom / Sonopus / XYZ-100" uma vez e reaproveita em qualquer cliente daqui pra
 * frente. Mesma ideia do catálogo de acessórios (AccessoryAggregate), agora pro próprio aparelho:
 * o pedido do cliente era parar de redigitar nome/marca/modelo a cada unidade física, deixando só
 * o que é dela (número de série, patrimônio, acessórios).
 *
 * Ganhou update/remove no api#109: com o catálogo sendo alimentado automaticamente (todo
 * equipamento salvo com marca/modelo digitados cria uma entrada aqui), erro de digitação e dado de
 * teste entravam e ficavam pra sempre, sem nenhuma tela pra limpar. Se existe catálogo global,
 * precisa existir manutenção dele.
 *
 * A edição tem uma consequência que o api#101 tinha evitado justamente por não existir: `equipments`
 * guarda uma cópia de nome/marca/modelo, então renomear aqui precisa alcançar aquela cópia — quem
 * faz isso é o EquipmentProjector, do lado de Equipments, reagindo a EquipmentModelUpdated.
 */
class EquipmentModelAggregate extends AggregateRoot
{
    private bool $removed = false;

    // O agregado precisa saber o trio atual pra conseguir informar o ANTERIOR ao renomear — ver
    // EquipmentModelUpdated pra por que isso importa. É o primeiro estado que este agregado guarda;
    // até o api#109 os apply* eram todos vazios.
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
