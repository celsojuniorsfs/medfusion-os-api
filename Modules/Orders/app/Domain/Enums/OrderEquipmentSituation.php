<?php

namespace Modules\Orders\Domain\Enums;

/**
 * Situação técnica de cada equipamento dentro da OS (api#140), independente do status da OS. Sem
 * máquina de estados: qualquer situação pode ir pra qualquer outra.
 */
enum OrderEquipmentSituation: string
{
    case InAnalysis = 'in_analysis';
    case AwaitingPart = 'awaiting_part';
    case ExternalRepair = 'external_repair';
    case Completed = 'completed';
    case ReturnedUnrepaired = 'returned_unrepaired';

    /**
     * "Resolvido" = não conta como pendente pra derivar o status da OS (api#140) nem pro alerta
     * por situação (api#147).
     */
    public function isResolved(): bool
    {
        return match ($this) {
            self::Completed, self::ReturnedUnrepaired => true,
            self::InAnalysis, self::AwaitingPart, self::ExternalRepair => false,
        };
    }

    /**
     * Marcos (em dias) do alerta por situação do equipamento (api#147) — 7 e 15 pros
     * não-resolvidos (`external_repair` inclusive), nada pros resolvidos.
     *
     * @return list<int>
     */
    public function alertMilestoneDays(): array
    {
        return $this->isResolved() ? [] : [7, 15];
    }
}
