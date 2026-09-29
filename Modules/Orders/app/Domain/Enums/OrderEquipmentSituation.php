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
}
