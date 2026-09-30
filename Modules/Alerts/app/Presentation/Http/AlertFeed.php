<?php

namespace Modules\Alerts\Presentation\Http;

use Illuminate\Support\Collection;
use Modules\Alerts\Domain\Enums\RevisionMilestone;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipmentSituationAlert;
use Modules\Orders\Infrastructure\ReadModels\OrderStalledAlert;

/**
 * Lista única dos alertas pendentes (api#160), somando as três fontes. As tabelas de OS parada e
 * de situação guardam uma linha por marco vencido e nunca apagam as de um status/situação já
 * superado — então cada consulta filtra "só o atual" e "só o marco mais alto" em SQL.
 */
class AlertFeed
{
    private const array FINAL_ORDER_STATUSES = ['canceled', 'not_approved', 'completed'];

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return collect()
            ->concat($this->stalledOrders())
            ->concat($this->equipmentSituations())
            ->concat($this->equipmentRevisions())
            ->sortByDesc('notified_at')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function stalledOrders(): Collection
    {
        return OrderStalledAlert::query()
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'order_stalled_alerts.order_id')
                    ->on('orders.status_changed_at', '=', 'order_stalled_alerts.status_changed_at');
            })
            ->whereRaw('order_stalled_alerts.milestone_days = (
                select max(a2.milestone_days) from order_stalled_alerts a2
                where a2.order_id = order_stalled_alerts.order_id
                and a2.status_changed_at = order_stalled_alerts.status_changed_at
            )')
            ->select('order_stalled_alerts.*')
            ->with('order.client')
            ->get()
            ->map(fn (OrderStalledAlert $alert) => [
                'id' => $alert->id,
                'type' => 'order_stalled',
                'title' => "OS {$alert->order->number} parada há mais de {$alert->milestone_days} dias",
                'notified_at' => $alert->notified_at->toISOString(),
                'milestone_days' => $alert->milestone_days,
                'milestone' => null,
                'billing_notified_at' => null,
                'order' => $this->order($alert->order),
                'equipment' => null,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function equipmentSituations(): Collection
    {
        return OrderEquipmentSituationAlert::query()
            ->join('orders', 'orders.id', '=', 'order_equipment_situation_alerts.order_id')
            ->join('order_equipments', function ($join) {
                $join->on('order_equipments.order_id', '=', 'order_equipment_situation_alerts.order_id')
                    ->on('order_equipments.equipment_id', '=', 'order_equipment_situation_alerts.equipment_id')
                    ->on('order_equipments.situation_changed_at', '=', 'order_equipment_situation_alerts.situation_changed_at');
            })
            ->whereNotIn('orders.status', self::FINAL_ORDER_STATUSES)
            ->whereRaw('order_equipment_situation_alerts.milestone_days = (
                select max(a2.milestone_days) from order_equipment_situation_alerts a2
                where a2.order_id = order_equipment_situation_alerts.order_id
                and a2.equipment_id = order_equipment_situation_alerts.equipment_id
                and a2.situation_changed_at = order_equipment_situation_alerts.situation_changed_at
            )')
            ->select('order_equipment_situation_alerts.*')
            ->with(['order.client', 'equipment'])
            ->get()
            ->map(fn (OrderEquipmentSituationAlert $alert) => [
                'id' => $alert->id,
                'type' => 'equipment_situation',
                'title' => "OS {$alert->order->number}: {$alert->equipment->name} parado há mais de {$alert->milestone_days} dias",
                'notified_at' => $alert->notified_at->toISOString(),
                'milestone_days' => $alert->milestone_days,
                'milestone' => null,
                'billing_notified_at' => null,
                'order' => $this->order($alert->order),
                'equipment' => ['id' => $alert->equipment_id, 'name' => $alert->equipment->name],
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function equipmentRevisions(): Collection
    {
        return EquipmentRevisionAlert::query()
            ->whereNull('client_contacted_at')
            ->whereNull('superseded_at')
            ->with(['equipment', 'order.client'])
            ->get()
            ->map(fn (EquipmentRevisionAlert $alert) => [
                'id' => $alert->id,
                'type' => 'equipment_revision',
                'title' => $alert->milestone === RevisionMilestone::Month6->value
                    ? "Acompanhamento: {$alert->equipment->name} (OS {$alert->order->number})"
                    : "Revisão anual próxima: {$alert->equipment->name} (OS {$alert->order->number})",
                'notified_at' => $alert->notified_at->toISOString(),
                'milestone_days' => null,
                'milestone' => $alert->milestone,
                'billing_notified_at' => $alert->billing_notified_at?->toISOString(),
                'order' => $this->order($alert->order),
                'equipment' => ['id' => $alert->equipment_id, 'name' => $alert->equipment->name],
            ]);
    }

    /**
     * @return array{id: string, number: int, client_name: ?string}
     */
    private function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'client_name' => $order->client?->name,
        ];
    }
}
