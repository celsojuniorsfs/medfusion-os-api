<?php

namespace Modules\Orders\Infrastructure\Projectors;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderEquipmentSituationChanged;
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
            // createdAt() do evento, não now() — evita que um replay reescreva a hora de
            // abertura pela hora do replay (api#135).
            'status_changed_at' => $event->createdAt(),
            // Vestigiais desde a #146 (virou por equipamento) — mantidas só como fallback em
            // onOrderEquipmentAttached() abaixo para OS's antigas; não expostas pela API.
            'preventive_maintenance' => $event->preventiveMaintenance,
            'calibration' => $event->calibration,
        ]);

        $this->forgetCache();
    }

    public function onOrderEquipmentAttached(OrderEquipmentAttached $event): void
    {
        // null = evento anterior à #146 (não carrega esses campos) — cai pro valor legado da OS
        // (coluna vestigial em orders, ver onOrderOpened()/onOrderUpdated()). Seguro porque
        // OrderOpened sempre projeta a linha antes de qualquer OrderEquipmentAttached do mesmo
        // agregado.
        //
        // lockForUpdate() trava a linha de `orders`, não a de `order_equipments` — serializa
        // duas requisições concorrentes anexando equipamento na MESMA OS. Sem isto, o COUNT()
        // abaixo (pra `position`) é um TOCTOU clássico: as duas leriam a mesma contagem antes de
        // qualquer INSERT confirmar, e dois equipamentos acabariam com a mesma posição — a letra
        // do certificado (api#61) deixando de ser única.
        $order = Order::whereKey($event->aggregateRootUuid())->lockForUpdate()->firstOrFail();

        // position (api#140): ordem de chegada na OS, vira a letra do certificado (api#61: 0 = A,
        // 1 = B...). Determinística no replay (eventos de um mesmo agregado reaplicam na ordem
        // original do stream) e reinicia após um OrderEquipmentsCleared.
        $position = OrderEquipment::where('order_id', $event->aggregateRootUuid())->count();

        $orderEquipment = OrderEquipment::create([
            // null só em eventos anteriores a orderEquipmentId existir — replay desses casos não
            // é determinístico.
            'id' => $event->orderEquipmentId ?? (string) Str::uuid(),
            'order_id' => $event->aggregateRootUuid(),
            'equipment_id' => $event->equipmentId,
            'name' => $event->name,
            'brand' => $event->brand,
            'model' => $event->model,
            'serial_number' => $event->serialNumber,
            'asset_tag' => $event->assetTag,
            'preventive_maintenance' => $event->preventiveMaintenance ?? $order->preventive_maintenance,
            'calibration' => $event->calibration ?? $order->calibration,
            // situation/situationChangedAt/completedAt nulos = equipamento novo, sem "situação
            // legada" a recuperar — default in_analysis, sem conclusão.
            'situation' => $event->situation ?? OrderEquipmentSituation::InAnalysis->value,
            'situation_changed_at' => $event->situationChangedAt ?? $event->createdAt(),
            'completed_at' => $event->completedAt,
            'position' => $position,
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

    /**
     * `completed_at` só é preenchida quando `to === completed`; em qualquer outra situação vira
     * null mesmo que já tivesse valor antes (ex.: reabertura em garantia) — sempre representa a
     * última conclusão NESTA situação atual, nunca uma conclusão anterior à reabertura (a
     * contagem de 12 meses da revisão anual reinicia do retrabalho, api#136).
     */
    public function onOrderEquipmentSituationChanged(OrderEquipmentSituationChanged $event): void
    {
        OrderEquipment::whereKey($event->orderEquipmentId)->update([
            'situation' => $event->to,
            'situation_changed_at' => $event->createdAt(),
            'completed_at' => $event->to === OrderEquipmentSituation::Completed->value ? $event->createdAt() : null,
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

        // total = mão de obra + soma dos itens (unit_price é opcional). Recalculado aqui para
        // não duplicar a regra entre create e update.
        $itemsTotal = $order->items()->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')->value('total');
        $order->update(['total' => ($order->labor_cost ?? 0) + $itemsTotal]);

        $this->forgetCache();
    }

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        Order::whereKey($event->aggregateRootUuid())->update([
            'status' => $event->to,
            // Reinicia a contagem de dias parada (api#135); createdAt() do evento, não now() —
            // mesmo motivo do onOrderOpened acima.
            'status_changed_at' => $event->createdAt(),
        ]);

        // Cascata de compatibilidade (api#140): OS's que viraram completed/warranty_repair antes
        // da situação por equipamento existir não têm OrderEquipmentSituationChanged no stream —
        // sem isso, um replay completo deixaria esses equipamentos presos em `in_analysis` para
        // sempre. Para dados gravados depois desta mudança é inofensivo: ChangeOrderStatus já
        // recusa completar com equipamento pendente, então não sobra ninguém em in_analysis aqui.
        if (in_array($event->to, ['completed', 'warranty_repair'], true)) {
            OrderEquipment::where('order_id', $event->aggregateRootUuid())
                ->whereNotIn('situation', ['completed', 'returned_unrepaired'])
                ->update([
                    'situation' => OrderEquipmentSituation::Completed->value,
                    'situation_changed_at' => $event->createdAt(),
                    'completed_at' => $event->createdAt(),
                ]);
        }

        $this->forgetCache();
    }

    /**
     * PUT /orders/{id} — disparado antes de OrderEquipmentsCleared/OrderItemsCleared (ver
     * UpdateOrder), então o total aqui já reflete o labor_cost novo; addItem() posteriores
     * incrementam a partir dele.
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
            // Vestigiais desde a #146 — mesmo motivo do onOrderOpened() acima.
            'preventive_maintenance' => $event->preventiveMaintenance,
            'calibration' => $event->calibration,
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
     * de versão — não `Cache::tags()->flush()` (achado em produção: instável em Redis/Valkey
     * gerenciado com réplica/cluster). Único lugar que escreve no read model, então também o
     * único que invalida o cache dele.
     *
     * `DB::afterCommit()`, não incremento direto: com o retry de `OrderController`
     * (`TRANSACTION_ATTEMPTS`, api#52), uma tentativa descartada por rollback já pode ter
     * chamado este método — um incremento direto sobreviveria ao rollback (cache não é desfeito
     * por ROLLBACK do SQL) e invalidaria a mais do que o necessário. `afterCommit()` só dispara
     * no commit real da transação mais externa.
     */
    private function forgetCache(): void
    {
        DB::afterCommit(fn () => Cache::increment('orders:cache-version'));
    }

    /**
     * Chamado pelo spatie antes de um `event-sourcing:replay --from=0` — sem isso, `onOrderOpened`
     * estoura por `number` duplicado. Filhos antes do pai (`order_items`/`order_equipments`
     * referenciam `orders`); FKs desligadas por segurança, mesmo padrão dos demais projectors.
     *
     * $aggregateUuid vem preenchido com `--aggregate-uuid=X` (replay de um agregado só) — o
     * spatie sempre chama este método, então zerar as tabelas inteiras aqui apagaria toda OS
     * para reconstruir uma só. Os filtros por `order_id`/`whereKey` só somem quando o replay é
     * completo (`$aggregateUuid === null`).
     */
    public function resetState(?string $aggregateUuid = null): void
    {
        Schema::withoutForeignKeyConstraints(function () use ($aggregateUuid) {
            // cascadeOnDelete não dispara aqui (FK desligada de propósito) — apaga os filhos de
            // order_equipments antes dele, senão sobra order_equipment_accessories órfã.
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
