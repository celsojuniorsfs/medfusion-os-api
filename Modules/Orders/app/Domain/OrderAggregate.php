<?php

namespace Modules\Orders\Domain;

use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderItemAdded;
use Modules\Orders\Domain\Events\OrderItemsCleared;
use Modules\Orders\Domain\Events\OrderOpened;
use Modules\Orders\Domain\Events\OrderPdfGenerated;
use Modules\Orders\Domain\Events\OrderStatusChanged;
use Modules\Orders\Domain\Events\OrderUpdated;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class OrderAggregate extends AggregateRoot
{
    private OrderStatus $status = OrderStatus::Open;

    public function open(
        int $number,
        string $date,
        string $clientId,
        string $userId,
        bool $pickedUp,
        bool $warranty,
        bool $technicalTraining,
        bool $onSiteQuote,
        bool $rental,
        ?string $reportedDefect,
        ?string $maintenancePlan,
        ?string $notes,
        ?string $paymentMethod,
        ?string $warrantyPeriod,
        ?string $proposalValidity,
        ?float $laborCost,
    ): self {
        $this->recordThat(new OrderOpened(
            $number, $date, $clientId, $userId,
            $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
            $reportedDefect, $maintenancePlan, $notes,
            $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
        ));

        return $this;
    }

    /**
     * @param  array<int, array{name: string, quantity: int}>  $accessories
     */
    public function attachEquipment(
        string $orderEquipmentId,
        ?string $equipmentId,
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        array $accessories,
    ): self {
        $this->recordThat(new OrderEquipmentAttached(
            $equipmentId, $name, $brand, $model, $serialNumber, $assetTag, $accessories, $orderEquipmentId,
        ));

        return $this;
    }

    public function addItem(float $quantity, string $description, ?float $unitPrice): self
    {
        $this->recordThat(new OrderItemAdded($quantity, $description, $unitPrice));

        return $this;
    }

    /**
     * PUT /orders/{id} (api#45) — mesmos campos de open(), menos number/clientId/userId, que
     * ficam por conta do controller decidir se mudam (number passa pelo re-check de duplicidade
     * antes de chegar aqui, ver UpdateOrder).
     */
    public function update(
        int $number,
        string $date,
        string $clientId,
        bool $pickedUp,
        bool $warranty,
        bool $technicalTraining,
        bool $onSiteQuote,
        bool $rental,
        ?string $reportedDefect,
        ?string $maintenancePlan,
        ?string $notes,
        ?string $paymentMethod,
        ?string $warrantyPeriod,
        ?string $proposalValidity,
        ?float $laborCost,
    ): self {
        $this->recordThat(new OrderUpdated(
            $number, $date, $clientId,
            $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
            $reportedDefect, $maintenancePlan, $notes,
            $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
        ));

        return $this;
    }

    /**
     * O agregado não rastreia os ids dos equipamentos/itens já anexados (só `$status`) — "trocar
     * a lista" é limpar tudo e anexar de novo (ver UpdateOrder), não um diff evento a evento.
     */
    public function clearEquipments(): self
    {
        $this->recordThat(new OrderEquipmentsCleared);

        return $this;
    }

    public function clearItems(): self
    {
        $this->recordThat(new OrderItemsCleared);

        return $this;
    }

    public function recordPdfGenerated(string $path, string $generatedAt): self
    {
        $this->recordThat(new OrderPdfGenerated($path, $generatedAt));

        return $this;
    }

    /**
     * @throws InvalidOrderStatusTransition quando a transição não está na tabela de
     *                                      api-conventions.md § Status da OS (ex.: completed → in_analysis).
     */
    public function changeStatus(OrderStatus $to): self
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new InvalidOrderStatusTransition($this->status, $to);
        }

        $this->recordThat(new OrderStatusChanged($this->status->value, $to->value));

        return $this;
    }

    protected function applyOrderOpened(OrderOpened $event): void
    {
        $this->status = OrderStatus::Open;
    }

    protected function applyOrderEquipmentAttached(OrderEquipmentAttached $event): void {}

    protected function applyOrderItemAdded(OrderItemAdded $event): void {}

    protected function applyOrderUpdated(OrderUpdated $event): void {}

    protected function applyOrderEquipmentsCleared(OrderEquipmentsCleared $event): void {}

    protected function applyOrderItemsCleared(OrderItemsCleared $event): void {}

    protected function applyOrderPdfGenerated(OrderPdfGenerated $event): void {}

    protected function applyOrderStatusChanged(OrderStatusChanged $event): void
    {
        $this->status = OrderStatus::from($event->to);
    }
}
