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
 * Chamada pelo comando agendado `orders:check-equipment-situations` (api#147), uma vez por dia —
 * sem fila (não roda worker em produção, ver docs/architecture.md). Recebe os e-mails já
 * resolvidos, mesmo motivo de CheckStalledOrders (api#135): quem é administrative/general_admin é
 * decisão do módulo Identity, composta no Command (Presentation), não aqui (Application).
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

        // Situações que alertam (S5): in_analysis, awaiting_part, external_repair — completed e
        // returned_unrepaired ficam de fora (OrderEquipmentSituation::alertMilestoneDays() já
        // devolve [] pros dois, aqui filtramos direto na query pra não trazer linha resolvida
        // nenhuma do banco).
        $alertableSituations = collect(OrderEquipmentSituation::cases())
            ->filter(fn (OrderEquipmentSituation $situation) => $situation->alertMilestoneDays() !== [])
            ->map(fn (OrderEquipmentSituation $situation) => $situation->value)
            ->all();

        $sent = 0;

        OrderEquipment::query()
            ->with('order')
            ->whereIn('situation', $alertableSituations)
            // Só equipamento de OS que ainda não chegou num status final (S5) — cancelada/não
            // aprovada/concluída. `partially_completed` e `warranty_repair` continuam alertando de
            // propósito: são exatamente os casos com equipamento pendente numa OS que já teve
            // outro(s) resolvido(s).
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', ['canceled', 'not_approved', 'completed']))
            ->whereNotNull('situation_changed_at')
            ->chunkById(100, function (Collection $equipments) use ($recipientEmails, &$sent) {
                foreach ($equipments as $equipment) {
                    // Um equipamento com problema não pode derrubar o comando inteiro e deixar o
                    // resto do lote sem ser verificado hoje — mesmo cuidado de CheckStalledOrders.
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
     * Grava o registro de idempotência ANTES de mandar o e-mail — mesmo raciocínio de
     * CheckStalledOrders::claimMilestone(): a constraint única
     * (order_equipment_id, situation_changed_at, milestone_days) é a rede de segurança de
     * verdade contra reenvio, mesmo com o cron sobrepondo.
     */
    private function claimMilestone(OrderEquipment $equipment, int $milestone): bool
    {
        try {
            OrderEquipmentSituationAlert::create([
                'id' => (string) Str::uuid(),
                'order_equipment_id' => $equipment->id,
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
