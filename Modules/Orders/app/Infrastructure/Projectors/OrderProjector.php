<?php

namespace Modules\Orders\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderItemAdded;
use Modules\Orders\Domain\Events\OrderItemsCleared;
use Modules\Orders\Domain\Events\OrderOpened;
use Modules\Orders\Domain\Events\OrderStatusChanged;
use Modules\Orders\Domain\Events\OrderUpdated;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Modules\Orders\Infrastructure\ReadModels\OrderItem;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class OrderProjector extends Projector
{
    public function onOrderOpened(OrderOpened $event): void
    {
        Order::create([
            'id' => $event->aggregateRootUuid(),
            'number' => $event->number,
            'date' => $event->date,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'picked_up' => $event->pickedUp,
            'warranty' => $event->warranty,
            'technical_training' => $event->technicalTraining,
            'on_site_quote' => $event->onSiteQuote,
            'rental' => $event->rental,
            'reported_defect' => $event->reportedDefect,
            'maintenance_plan' => $event->maintenancePlan,
            'notes' => $event->notes,
            'payment_method' => $event->paymentMethod,
            'warranty_period' => $event->warrantyPeriod,
            'proposal_validity' => $event->proposalValidity,
            'labor_cost' => $event->laborCost,
            'total' => $event->laborCost ?? 0,
            'status' => 'open',
        ]);

        $this->forgetCache();
    }

    public function onOrderEquipmentAttached(OrderEquipmentAttached $event): void
    {
        OrderEquipment::create([
            'order_id' => $event->aggregateRootUuid(),
            'equipment_id' => $event->equipmentId,
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
            'accessories' => $event->accessories,
        ]);

        $this->forgetCache();
    }

    public function onOrderItemAdded(OrderItemAdded $event): void
    {
        $order = Order::findOrFail($event->aggregateRootUuid());

        OrderItem::create([
            'order_id' => $order->id,
            'quantity' => $event->quantity,
            'description' => $event->description,
            'unit_price' => $event->unitPrice,
        ]);

        // total = mão de obra + soma dos itens com valor (unit_price opcional — ver
        // OrderItem em openapi.yaml). Recalculado a cada item para não duplicar a regra em
        // dois lugares (create + update).
        $itemsTotal = $order->items()->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')->value('total');
        $order->update(['total' => ($order->labor_cost ?? 0) + $itemsTotal]);

        $this->forgetCache();
    }

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        Order::whereKey($event->aggregateRootUuid())->update([
            'status' => $event->to,
        ]);

        $this->forgetCache();
    }

    /**
     * PUT /orders/{id} (api#45) — sempre disparado antes de OrderEquipmentsCleared/
     * OrderItemsCleared (ver UpdateOrder), então o total recalculado aqui já reflete o
     * labor_cost novo; os addItem() que vierem depois incrementam a partir dele.
     */
    public function onOrderUpdated(OrderUpdated $event): void
    {
        $order = Order::findOrFail($event->aggregateRootUuid());

        $itemsTotal = $order->items()->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')->value('total');

        $order->update([
            'number' => $event->number,
            'date' => $event->date,
            'client_id' => $event->clientId,
            'picked_up' => $event->pickedUp,
            'warranty' => $event->warranty,
            'technical_training' => $event->technicalTraining,
            'on_site_quote' => $event->onSiteQuote,
            'rental' => $event->rental,
            'reported_defect' => $event->reportedDefect,
            'maintenance_plan' => $event->maintenancePlan,
            'notes' => $event->notes,
            'payment_method' => $event->paymentMethod,
            'warranty_period' => $event->warrantyPeriod,
            'proposal_validity' => $event->proposalValidity,
            'labor_cost' => $event->laborCost,
            'total' => ($event->laborCost ?? 0) + $itemsTotal,
        ]);

        $this->forgetCache();
    }

    public function onOrderEquipmentsCleared(OrderEquipmentsCleared $event): void
    {
        OrderEquipment::where('order_id', $event->aggregateRootUuid())->delete();

        $this->forgetCache();
    }

    public function onOrderItemsCleared(OrderItemsCleared $event): void
    {
        $order = Order::findOrFail($event->aggregateRootUuid());

        OrderItem::where('order_id', $order->id)->delete();

        $order->update(['total' => $order->labor_cost ?? 0]);

        $this->forgetCache();
    }

    /**
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) — uma tag por módulo,
     * limpa por inteiro a cada evento seu, em vez de TTL: o Projector já é o único lugar que
     * escreve no read model, então vira também o único lugar que invalida o cache dele.
     */
    private function forgetCache(): void
    {
        Cache::tags(['orders'])->flush();
    }
}
