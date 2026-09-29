<?php

namespace Modules\Alerts\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Alerts\Domain\Enums\RevisionMilestone;
use Modules\Alerts\Infrastructure\Mail\EquipmentRevisionBillingMail;
use Modules\Alerts\Infrastructure\Mail\EquipmentRevisionMail;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;

/**
 * Chamada pelo comando agendado `alerts:check-equipment-revisions` (api#136), uma vez por dia —
 * sem fila (não roda worker em produção, ver docs/architecture.md). $eligibleCycles já vem
 * resolvido pelo Command (Presentation) — Application não importa o módulo Orders. Cada entrada é
 * o ciclo ATUAL do equipamento (última resolução de qualquer tipo, só entra aqui se ela for
 * `completed` + preventiva); um equipamento sem entrada aqui não tem ciclo elegível agora.
 */
class CheckEquipmentRevisions
{
    /**
     * @param  list<array{equipment_id: string, order_id: string, base_date: string, equipment_name: string, order_number: int, client_name: ?string}>  $eligibleCycles
     * @param  list<string>  $recipientEmails  administrative + general_admin, avisos de mês 6/11
     * @param  list<string>  $billingEmails  só general_admin, cobrança de 7 dias
     * @return array{revisions_sent: int, billing_sent: int}
     */
    public function __invoke(array $eligibleCycles, array $recipientEmails, array $billingEmails): array
    {
        $cyclesByEquipmentId = collect($eligibleCycles)->keyBy('equipment_id');

        $this->supersedeStaleCycles($cyclesByEquipmentId);

        $revisionsSent = 0;

        foreach ($eligibleCycles as $cycle) {
            try {
                $revisionsSent += $this->checkCycle($cycle, $recipientEmails);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return [
            'revisions_sent' => $revisionsSent,
            'billing_sent' => $this->checkBilling($billingEmails),
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
     * @param  array{equipment_id: string, order_id: string, base_date: string, equipment_name: string, order_number: int, client_name: ?string}  $cycle
     * @param  list<string>  $recipientEmails
     */
    private function checkCycle(array $cycle, array $recipientEmails): int
    {
        $baseDate = Carbon::parse($cycle['base_date']);
        $sent = 0;

        foreach (RevisionMilestone::cases() as $milestone) {
            $dueDate = $baseDate->copy()->addMonths($milestone->months());

            if ($dueDate->isFuture() || ! $this->claimRevision($cycle, $milestone, $baseDate, $dueDate)) {
                continue;
            }

            foreach ($recipientEmails as $email) {
                Mail::to($email)->send(new EquipmentRevisionMail($cycle, $milestone, $dueDate));
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Grava a idempotência ANTES de mandar o e-mail — a constraint única
     * (equipment_id, base_date, milestone) barra reenvio.
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

    /**
     * @param  list<string>  $billingEmails
     */
    private function checkBilling(array $billingEmails): int
    {
        if ($billingEmails === []) {
            return 0;
        }

        $sent = 0;

        EquipmentRevisionAlert::query()
            ->whereNull('superseded_at')
            ->whereNull('client_contacted_at')
            ->whereNull('billing_notified_at')
            ->where('notified_at', '<=', now()->subDays(7))
            ->chunkById(100, function (Collection $alerts) use ($billingEmails, &$sent) {
                foreach ($alerts as $alert) {
                    try {
                        $sent += $this->claimBilling($alert, $billingEmails) ? 1 : 0;
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $sent;
    }

    /**
     * @param  list<string>  $billingEmails
     */
    private function claimBilling(EquipmentRevisionAlert $alert, array $billingEmails): bool
    {
        // UPDATE condicional em vez de INSERT: a linha já existe, então a idempotência aqui é
        // "só quem ainda está null grava" — barra duas execuções sobrepostas mandando a cobrança
        // duas vezes, mesmo princípio da constraint única de claimRevision().
        $claimed = EquipmentRevisionAlert::whereKey($alert->id)
            ->whereNull('billing_notified_at')
            ->update(['billing_notified_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        foreach ($billingEmails as $email) {
            Mail::to($email)->send(new EquipmentRevisionBillingMail($alert));
        }

        return true;
    }
}
