<?php

namespace Modules\Orders\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderItemAdded;
use Modules\Orders\Domain\Events\OrderItemsCleared;
use Modules\Orders\Domain\Events\OrderOpened;
use Modules\Orders\Domain\Events\OrderPdfGenerated;
use Modules\Orders\Domain\Events\OrderStatusChanged;
use Modules\Orders\Domain\Events\OrderUpdated;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipmentAccessory;
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
        $orderEquipment = OrderEquipment::create([
            // null só em eventos gravados antes de orderEquipmentId existir (replay não-determinístico
            // pra esses casos específicos, igual já era antes desta mudança).
            'id' => $event->orderEquipmentId ?? (string) Str::uuid(),
            'order_id' => $event->aggregateRootUuid(),
            'equipment_id' => $event->equipmentId,
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
        ]);

        foreach ($event->accessories as $position => $accessory) {
            $orderEquipment->accessories()->create([
                'name' => $accessory['name'],
                'quantity' => $accessory['quantity'],
                'position' => $position,
            ]);
        }

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

    public function onOrderPdfGenerated(OrderPdfGenerated $event): void
    {
        Order::whereKey($event->aggregateRootUuid())->update([
            'pdf_path' => $event->path,
            'pdf_generated_at' => $event->generatedAt,
        ]);
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
     * Invalida a listagem em cache (ver docs/architecture.md § Cache) incrementando um contador
     * de versão — não `Cache::tags()->flush()` (achado em produção: operação multi-chave, fonte
     * conhecida de comportamento inconsistente em Redis/Valkey gerenciado com réplica/cluster).
     * O Projector já é o único lugar que escreve no read model, então vira também o único lugar
     * que invalida o cache dele.
     *
     * `DB::afterCommit()`, não incremento direto: desde que `OrderController` passou a rodar com
     * retry (`TRANSACTION_ATTEMPTS`, api#52), uma tentativa que esbarra em contenção e é
     * descartada por rollback já pode ter chamado este método antes de falhar — um incremento
     * direto aqui sobreviveria ao rollback (Cache/Valkey não é desfeito por ROLLBACK do SQL) e
     * invalidaria o cache uma vez a mais do que o necessário por tentativa perdida.
     * `afterCommit()` adia pro commit de verdade da transação mais externa (e roda na hora se não
     * houver transação nenhuma em aberto) — nunca dispara pra uma tentativa que não vingou.
     */
    private function forgetCache(): void
    {
        DB::afterCommit(fn () => Cache::increment('orders:cache-version'));
    }

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0` (ver Projectionist::replay)
     * — sem isso, `onOrderOpened` estoura por `number` duplicado. Filhos antes do pai
     * (`order_items`/`order_equipments` referenciam `orders`), FKs desligadas por segurança (não
     * é estritamente necessário aqui — nenhum outro módulo referencia `orders` — mas mantém o
     * mesmo padrão dos demais projectors).
     *
     * $aggregateUuid vem preenchido com `--aggregate-uuid=X` (replay de um agregado só) — o
     * spatie chama isto de qualquer forma (ver Projectionist::replay), então zerar as tabelas
     * inteiras aqui apagaria toda OS pra reconstruir só uma. Os filtros por `order_id`/`whereKey`
     * somem quando o replay é de verdade completo (`$aggregateUuid === null`).
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            // cascadeOnDelete não dispara aqui dentro (FK desligada de propósito) — apaga os
            // filhos de order_equipments explicitamente antes dele, senão um replay deixaria
            // order_equipment_accessories órfã apontando pra linhas já apagadas.
            OrderEquipmentAccessory::when(
                $aggregateUuid !== null,
                fn ($query) => $query->whereIn(
                    'order_equipment_id',
                    OrderEquipment::select('id')->where('order_id', $aggregateUuid),
                ),
            )->delete();

            OrderItem::when($aggregateUuid !== null, fn ($query) => $query->where('order_id', $aggregateUuid))->delete();
            OrderEquipment::when($aggregateUuid !== null, fn ($query) => $query->where('order_id', $aggregateUuid))->delete();
            Order::when($aggregateUuid !== null, fn ($query) => $query->whereKey($aggregateUuid))->delete();
        });

        $this->forgetCache();
    }
}
