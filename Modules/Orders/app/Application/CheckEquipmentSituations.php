<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipmentSituationAlert;

/**
 * Chamada pelo comando agendado `orders:check-equipment-situations` (api#147), uma vez por dia. O
 * aviso é só pelo painel de alertas do front-end (api#158) — este comando só grava o marco em
 * order_equipment_situation_alerts, não manda e-mail.
 */
class CheckEquipmentSituations
{
    /**
     * @return int quantidade de marcos (milestone × equipamento) gravados nesta execução
     */
    public function __invoke(): int
    {
        $alertableSituations = collect(OrderEquipmentSituation::cases())
            ->filter(fn (OrderEquipmentSituation $situation) => $situation->alertMilestoneDays() !== [])
            ->map(fn (OrderEquipmentSituation $situation) => $situation->value)
            ->all();

        $claimed = 0;

        OrderEquipment::query()
            ->whereIn('situation', $alertableSituations)
            // partially_completed/warranty_repair continuam alertando de propósito — são
            // exatamente o caso de equipamento pendente numa OS que já teve outro(s) resolvido(s).
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', ['canceled', 'not_approved', 'completed']))
            ->whereNotNull('situation_changed_at')
            // Sem equipment_id (saiu do catálogo) não tem identidade estável pra chavear o
            // rastro de idempotência — sem alerta.
            ->whereNotNull('equipment_id')
            ->chunkById(100, function (Collection $equipments) use (&$claimed) {
                foreach ($equipments as $equipment) {
                    try {
                        $claimed += $this->checkEquipment($equipment);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $claimed;
    }

    private function checkEquipment(OrderEquipment $equipment): int
    {
        $situation = OrderEquipmentSituation::from($equipment->situation);
        $daysElapsed = (int) $equipment->situation_changed_at->diffInDays(now());
        $claimed = 0;

        foreach ($situation->alertMilestoneDays() as $milestone) {
            if ($daysElapsed < $milestone || ! $this->claimMilestone($equipment, $milestone)) {
                continue;
            }

            $claimed++;
        }

        return $claimed;
    }

    /**
     * Grava a idempotência — a constraint única (order_id, equipment_id, situation_changed_at,
     * milestone_days) barra reenvio. Chaveada pelo equipment_id do catálogo, não
     * order_equipment_id (efêmero — troca a cada edição da OS, api#149).
     */
    private function claimMilestone(OrderEquipment $equipment, int $milestone): bool
    {
        try {
            OrderEquipmentSituationAlert::create([
                'id' => (string) Str::uuid(),
                'order_id' => $equipment->order_id,
                'equipment_id' => $equipment->equipment_id,
                'situation' => $equipment->situation,
                'situation_changed_at' => $equipment->situation_changed_at,
                'milestone_days' => $milestone,
                'notified_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
