<?php

namespace Modules\Orders\Domain\Enums;

/**
 * Situação técnica de cada equipamento dentro da OS (api#140), independente do status da OS —
 * validado com o cliente na página "Situação por Equipamento" (S2, 28/09/2026). Sem máquina de
 * estados: qualquer situação pode ir pra qualquer outra (o cliente pediu flexibilidade, não uma
 * sequência fixa — ex.: um equipamento pode voltar de "aguardando peça" pra "em análise").
 */
enum OrderEquipmentSituation: string
{
    case InAnalysis = 'in_analysis';
    case AwaitingPart = 'awaiting_part';
    case ExternalRepair = 'external_repair';
    case Completed = 'completed';
    case ReturnedUnrepaired = 'returned_unrepaired';

    /**
     * "Resolvido" = não conta mais como pendente pra derivar o status da OS (api#140) nem pro
     * alerta por situação (api#147, que só alerta os não-resolvidos).
     */
    public function isResolved(): bool
    {
        return match ($this) {
            self::Completed, self::ReturnedUnrepaired => true,
            self::InAnalysis, self::AwaitingPart, self::ExternalRepair => false,
        };
    }

    /**
     * Marcos (em dias) do alerta por situação do equipamento (api#147), validado com o cliente na
     * S5 — 7 e 15, mesmos pros três não-resolvidos (`external_repair` inclusive, "pra lembrar de
     * cobrar o fornecedor"). Resolvido não alerta. Mesmo espírito de
     * `OrderStatus::stalledAlertMilestoneDays()`, mas fixo por situação, não por status.
     *
     * @return list<int>
     */
    public function alertMilestoneDays(): array
    {
        return $this->isResolved() ? [] : [7, 15];
    }
}
