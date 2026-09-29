<?php

namespace Modules\Alerts\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Alerts\Domain\Enums\RevisionMilestone;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;

/**
 * Chamada pelo comando agendado `alerts:check-equipment-revisions` (api#136), uma vez por dia. O
 * aviso é só pelo painel de alertas do front-end (api#158) — não manda e-mail. $eligibleCycles já
 * vem resolvido pelo Command (Presentation) — Application não importa o módulo Orders. Cada
 * entrada é o ciclo ATUAL do equipamento (última resolução de qualquer tipo, só entra aqui se ela
 * for `completed` + preventiva); um equipamento sem entrada aqui não tem ciclo elegível agora.
 */
class CheckEquipmentRevisions
{
    /**
     * @param  list<array{equipment_id: string, order_id: string, base_date: string}>  $eligibleCycles
     * @return array{revisions_claimed: int, billing_claimed: int}
     */
    public function __invoke(array $eligibleCycles): array
    {
        $cyclesByEquipmentId = collect($eligibleCycles)->keyBy('equipment_id');

        $this->supersedeStaleCycles($cyclesByEquipmentId);

        $revisionsClaimed = 0;

        foreach ($eligibleCycles as $cycle) {
            try {
                $revisionsClaimed += $this->checkCycle($cycle);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return [
            'revisions_claimed' => $revisionsClaimed,
            'billing_claimed' => $this->checkBilling(),
        ];
    }

    /**
     * Um equipamento cujo ciclo elegível mudou (nova resolução, ou nenhuma mais elegível) supera
     * qualquer alerta pendente do ciclo anterior (Q2) — o que ainda não tinha disparado não
     * dispara mais (não sobra elegível pra ele), e uma cobrança pendente do ciclo antigo some da
     * fila (ver checkBilling, que só olha superseded_at null).
     *
     * @param  Collection<string, array{base_date: string}>  $cyclesByEquipmentId
     */
    private function supersedeStaleCycles(Collection $cyclesByEquipmentId): void
    {
        EquipmentRevisionAlert::query()
            ->whereNull('superseded_at')
            ->distinct()
            ->pluck('equipment_id')
            ->each(function (string $equipmentId) use ($cyclesByEquipmentId) {
                $currentBaseDate = $cyclesByEquipmentId->get($equipmentId)['base_date'] ?? null;

                $query = EquipmentRevisionAlert::where('equipment_id', $equipmentId)->whereNull('superseded_at');

                if ($currentBaseDate !== null) {
                    $query->where('base_date', '!=', Carbon::parse($currentBaseDate));
                }

                $query->update(['superseded_at' => now()]);
            });
    }

    /**
     * @param  array{equipment_id: string, order_id: string, base_date: string}  $cycle
     */
    private function checkCycle(array $cycle): int
    {
        $baseDate = Carbon::parse($cycle['base_date']);
        $claimed = 0;

        foreach (RevisionMilestone::cases() as $milestone) {
            $dueDate = $baseDate->copy()->addMonths($milestone->months());

            if ($dueDate->isFuture() || ! $this->claimRevision($cycle, $milestone, $baseDate, $dueDate)) {
                continue;
            }

            $claimed++;
        }

        return $claimed;
    }

    /**
     * Grava a idempotência — a constraint única (equipment_id, base_date, milestone) barra
     * duplicata.
     *
     * @param  array{equipment_id: string, order_id: string}  $cycle
     */
    private function claimRevision(array $cycle, RevisionMilestone $milestone, Carbon $baseDate, Carbon $dueDate): bool
    {
        try {
            EquipmentRevisionAlert::create([
                'id' => (string) Str::uuid(),
                'equipment_id' => $cycle['equipment_id'],
                'order_id' => $cycle['order_id'],
                'base_date' => $baseDate,
                'milestone' => $milestone->value,
                'due_date' => $dueDate,
                'notified_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function checkBilling(): int
    {
        $claimed = 0;

        EquipmentRevisionAlert::query()
            ->whereNull('superseded_at')
            ->whereNull('client_contacted_at')
            ->whereNull('billing_notified_at')
            ->where('notified_at', '<=', now()->subDays(7))
            ->chunkById(100, function (Collection $alerts) use (&$claimed) {
                foreach ($alerts as $alert) {
                    try {
                        $claimed += $this->claimBilling($alert) ? 1 : 0;
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $claimed;
    }

    /**
     * UPDATE condicional em vez de INSERT: a linha já existe, então a idempotência aqui é "só
     * quem ainda está null grava" — barra duas execuções sobrepostas marcando a cobrança duas
     * vezes, mesmo princípio da constraint única de claimRevision(). billing_notified_at marca
     * "mais de 7 dias sem contato" pro front-end destacar — não dispara mais nada sozinho.
     */
    private function claimBilling(EquipmentRevisionAlert $alert): bool
    {
        return EquipmentRevisionAlert::whereKey($alert->id)
            ->whereNull('billing_notified_at')
            ->update(['billing_notified_at' => now()]) > 0;
    }
}
