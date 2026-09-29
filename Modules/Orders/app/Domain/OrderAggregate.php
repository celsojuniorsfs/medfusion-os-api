<?php

namespace Modules\Orders\Domain;

use Modules\Orders\Domain\Enums\OrderEquipmentApprovalStatus;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Events\OrderEquipmentApprovalStatusChanged;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderEquipmentSituationChanged;
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
        // Vestigiais desde a #146: preventiva/calibração passaram a ser por equipamento
        // (attachEquipment() abaixo). Continuam aqui só porque OrderOpened já foi gravado com
        // esses campos e não pode perdê-los (CLAUDE.md) — nada mais os alimenta com valor real.
        bool $preventiveMaintenance = false,
        bool $calibration = false,
    ): self {
        $this->recordThat(new OrderOpened(
            $number, $date, $clientId, $userId,
            $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
            $reportedDefect, $maintenancePlan, $notes,
            $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
            $preventiveMaintenance, $calibration,
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
        ?bool $preventiveMaintenance = null,
        ?bool $calibration = null,
        ?string $situation = null,
        ?string $situationChangedAt = null,
        ?string $completedAt = null,
        ?string $approvalStatus = null,
        ?string $approvalStatusChangedAt = null,
        ?float $laborCost = null,
    ): self {
        $this->recordThat(new OrderEquipmentAttached(
            $equipmentId, $name, $brand, $model, $serialNumber, $assetTag, $accessories, $orderEquipmentId,
            $preventiveMaintenance, $calibration, $situation, $situationChangedAt, $completedAt,
            $approvalStatus, $approvalStatusChangedAt, $laborCost,
        ));

        return $this;
    }

    public function changeEquipmentApprovalStatus(
        string $orderEquipmentId,
        ?OrderEquipmentApprovalStatus $from,
        OrderEquipmentApprovalStatus $to,
    ): self {
        $this->recordThat(new OrderEquipmentApprovalStatusChanged($orderEquipmentId, $from?->value, $to->value));

        return $this;
    }

    /**
     * api#140 — situação técnica de UM equipamento, independente do status da OS. `$from` não é
     * gravado por redundância: o agregado não rastreia situação por equipamento (mesmo motivo de
     * `attachEquipment()` não alimentar nenhum estado aqui — quem lê é sempre o read model, ver
     * ChangeOrderEquipmentSituation), mas o evento guarda `from` porque é dado de auditoria útil
     * (e replay-safe: uma vez gravado, nunca muda).
     */
    public function changeEquipmentSituation(
        string $orderEquipmentId,
        OrderEquipmentSituation $from,
        OrderEquipmentSituation $to,
    ): self {
        $this->recordThat(new OrderEquipmentSituationChanged($orderEquipmentId, $from->value, $to->value));

        return $this;
    }

    public function addItem(float $quantity, string $description, ?float $unitPrice, ?string $orderEquipmentId = null): self
    {
        $this->recordThat(new OrderItemAdded($quantity, $description, $unitPrice, $orderEquipmentId));

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
        // Vestigiais desde a #146 — ver o mesmo comentário em open() acima.
        bool $preventiveMaintenance = false,
        bool $calibration = false,
    ): self {
        $this->recordThat(new OrderUpdated(
            $number, $date, $clientId,
            $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
            $reportedDefect, $maintenancePlan, $notes,
            $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
            $preventiveMaintenance, $calibration,
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

    /**
     * @param  ?list<string>  $orderEquipmentIds  api#149 — null = orçamento da OS inteira.
     */
    public function recordPdfGenerated(string $path, string $generatedAt, ?array $orderEquipmentIds = null): self
    {
        $this->recordThat(new OrderPdfGenerated($path, $generatedAt, $orderEquipmentIds));

        return $this;
    }

    /**
     * $automatic (api#140) — `true` só quando vem de `OrderStatus::derivedFromEquipments()`
     * (ver DeriveOrderStatusFromEquipments/ChangeOrderEquipmentSituation): entrar em
     * `partially_completed`, ou sair dele de volta pra `approved`, não é uma decisão manual (ver
     * `OrderStatus::isAutomaticOnlyTransition()`), e um PATCH /orders/{id}/status tentando isso é
     * rejeitado com a mesma exceção de uma transição fora da tabela.
     *
     * @throws InvalidOrderStatusTransition quando a transição não está na tabela de
     *                                      api-conventions.md § Status da OS (ex.: completed → in_analysis)
     *                                      ou é automática-só e não veio marcada como tal.
     */
    public function changeStatus(OrderStatus $to, bool $automatic = false): self
    {
        if (! $this->status->canTransitionTo($to)) {
            throw new InvalidOrderStatusTransition($this->status, $to);
        }

        if (! $automatic && $this->status->isAutomaticOnlyTransition($to)) {
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

    protected function applyOrderEquipmentSituationChanged(OrderEquipmentSituationChanged $event): void {}

    protected function applyOrderEquipmentApprovalStatusChanged(OrderEquipmentApprovalStatusChanged $event): void {}

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
