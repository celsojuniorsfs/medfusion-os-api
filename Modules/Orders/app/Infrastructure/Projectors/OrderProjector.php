<?php

namespace Modules\Orders\Infrastructure\Projectors;

use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderItemAdded;
use Modules\Orders\Domain\Events\OrderOpened;
use Modules\Orders\Domain\Events\OrderStatusChanged;
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
    }

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        Order::whereKey($event->aggregateRootUuid())->update([
            'status' => $event->to,
        ]);
    }
}
