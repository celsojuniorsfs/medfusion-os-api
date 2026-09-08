<?php

namespace Modules\Orders\Domain;

use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderItemAdded;
use Modules\Orders\Domain\Events\OrderOpened;
use Modules\Orders\Domain\Events\OrderStatusChanged;
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

    public function attachEquipment(
        ?string $equipmentId,
        string $name,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $assetTag,
        ?string $accessories,
    ): self {
        $this->recordThat(new OrderEquipmentAttached(
            $equipmentId, $name, $brand, $model, $serialNumber, $assetTag, $accessories,
        ));

        return $this;
    }

    public function addItem(float $quantity, string $description, ?float $unitPrice): self
    {
        $this->recordThat(new OrderItemAdded($quantity, $description, $unitPrice));

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

    protected function applyOrderStatusChanged(OrderStatusChanged $event): void
    {
        $this->status = OrderStatus::from($event->to);
    }
}
