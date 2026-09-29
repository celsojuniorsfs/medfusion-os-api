<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Infrastructure\Mail\OrderEquipmentSituationMail;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipmentSituationAlert;

/**
 * Chamada pelo comando agendado `orders:check-equipment-situations` (api#147). Recebe os e-mails
 * já resolvidos — quem é administrative/general_admin é composto no Command (Presentation), não
 * aqui (Application não importa outro módulo, ver docs/architecture.md).
 */
class CheckEquipmentSituations
{
    /**
     * @param  list<string>  $recipientEmails
     * @return int quantidade de alertas (marco × equipamento) disparados nesta execução
     */
    public function __invoke(array $recipientEmails): int
    {
        if ($recipientEmails === []) {
            return 0;
        }

        $alertableSituations = collect(OrderEquipmentSituation::cases())
            ->filter(fn (OrderEquipmentSituation $situation) => $situation->alertMilestoneDays() !== [])
            ->map(fn (OrderEquipmentSituation $situation) => $situation->value)
            ->all();

        $sent = 0;

        OrderEquipment::query()
            ->with('order.client')
            ->whereIn('situation', $alertableSituations)
            // partially_completed/warranty_repair continuam alertando de propósito — são
            // exatamente o caso de equipamento pendente numa OS que já teve outro(s) resolvido(s).
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', ['canceled', 'not_approved', 'completed']))
            ->whereNotNull('situation_changed_at')
            // Sem equipment_id (saiu do catálogo) não tem identidade estável pra chavear o
            // rastro de idempotência — sem alerta.
            ->whereNotNull('equipment_id')
            ->chunkById(100, function (Collection $equipments) use ($recipientEmails, &$sent) {
                foreach ($equipments as $equipment) {
                    try {
                        $sent += $this->checkEquipment($equipment, $recipientEmails);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $sent;
    }

    /**
     * @param  list<string>  $recipientEmails
     */
    private function checkEquipment(OrderEquipment $equipment, array $recipientEmails): int
    {
        $situation = OrderEquipmentSituation::from($equipment->situation);
        $daysElapsed = (int) $equipment->situation_changed_at->diffInDays(now());
        $sent = 0;

        foreach ($situation->alertMilestoneDays() as $milestone) {
            if ($daysElapsed < $milestone || ! $this->claimMilestone($equipment, $milestone)) {
                continue;
            }

            foreach ($recipientEmails as $email) {
                Mail::to($email)->send(new OrderEquipmentSituationMail($equipment, $milestone));
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Grava a idempotência ANTES de mandar o e-mail — a constraint única (order_id, equipment_id,
     * situation_changed_at, milestone_days) barra reenvio. Chaveada pelo equipment_id do catálogo,
     * não order_equipment_id (efêmero — troca a cada edição da OS, api#149).
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
