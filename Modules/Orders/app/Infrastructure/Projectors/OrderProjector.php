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
            // createdAt() do evento, não now() — senão um event-sourcing:replay reescreveria
            // toda OS com a hora do replay, e não da abertura de verdade (api#135).
            'status_changed_at' => $event->createdAt(),
            // Vestigiais desde a #146 (preventiva/calibração viraram por equipamento) — a
            // coluna continua existindo e sendo escrita só pra servir de fallback em
            // onOrderEquipmentAttached() abaixo, pra OS's abertas antes da #146, cujo
            // OrderEquipmentAttached não carrega esse valor. Nunca exposta pela API (sumiu de
            // OrderResource/OrderRequest na #146).
            'preventive_maintenance' => $event->preventiveMaintenance,
            'calibration' => $event->calibration,
        ]);

        $this->forgetCache();
    }

    public function onOrderEquipmentAttached(OrderEquipmentAttached $event): void
    {
        // null aqui = evento gravado antes da #146, que não carrega esses campos — cai pro
        // valor legado da OS (coluna vestigial em orders, ver onOrderOpened()/onOrderUpdated()
        // acima), que já foi projetado antes deste evento na mesma sequência do stream (open/
        // update sempre precede attachEquipment na mesma OS). Sem esse fallback, um
        // `event-sourcing:replay` zeraria silenciosamente a marcação de todo equipamento
        // anexado antes desta mudança — a OS::findOrFail() é segura aqui porque OrderOpened
        // já criou a linha antes de qualquer OrderEquipmentAttached do mesmo agregado rodar.
        $order = Order::findOrFail($event->aggregateRootUuid());

        // position (api#140): ordem de chegada dentro da OS, vira a letra do certificado
        // (api#61: 0 = A, 1 = B...). Determinística no replay — eventos de um mesmo agregado
        // sempre reaplicam na ordem original do stream — e volta a contar do zero depois de um
        // OrderEquipmentsCleared (UpdateOrder limpa a tabela antes de reanexar, ver
        // onOrderEquipmentsCleared() abaixo), então a edição preserva a ordem do payload novo.
        $position = OrderEquipment::where('order_id', $event->aggregateRootUuid())->count();

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
            'preventive_maintenance' => $event->preventiveMaintenance ?? $order->preventive_maintenance,
            'calibration' => $event->calibration ?? $order->calibration,
            // situation/situationChangedAt/completedAt nulos = equipamento novo (não existe
            // "situação legada" antes da #140 pra recuperar, ao contrário de
            // preventiveMaintenance/calibration acima) — default in_analysis, sem conclusão.
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
     * api#140 — `completed_at` só é preenchida quando `to === completed`; noutra situação fica
     * null, mesmo que já tivesse valor antes (ex.: reabertura em garantia) — o campo sempre
     * significa "a última vez que ESTE equipamento foi marcado concluído nesta situação atual",
     * nunca um histórico velho de uma conclusão anterior à reabertura (útil pra revisão anual,
     * api#136: a contagem de 12 meses reinicia do retrabalho, não da conclusão original).
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
            // Reinicia a contagem de dias parada (api#135) — createdAt() do evento, não now(),
            // pelo mesmo motivo do onOrderOpened acima.
            'status_changed_at' => $event->createdAt(),
        ]);

        // Cascata de compatibilidade (api#140): OS's que viraram completed/warranty_repair ANTES
        // da situação por equipamento existir não têm OrderEquipmentSituationChanged nenhum no
        // stream — sem isso, um `event-sourcing:replay` completo deixaria os equipamentos delas
        // presos em `in_analysis` pra sempre (OrderEquipmentAttached tão antigo não carrega
        // situação real, o projector usa o default). Pra dado gravado DEPOIS desta mudança isto é
        // inofensivo: ChangeOrderStatus já recusa completar manualmente com equipamento
        // pendente, e a transição automática pra completed só ocorre quando todos já estão
        // resolvidos — não sobra ninguém em in_analysis aqui pra marcar.
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
