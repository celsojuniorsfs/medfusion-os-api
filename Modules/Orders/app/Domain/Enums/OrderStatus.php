<?php

namespace Modules\Orders\Domain\Enums;

/**
 * Tabela de transições fechada na F3 (ver docs/api-conventions.md § Status da OS). O prazo de
 * garantia que limita completed → warranty_repair é uma regra de negócio sobre a data da OS,
 * não sobre o estado em si — fica para quando o comando de mudança de status for implementado
 * de verdade (fora desta sessão), não faz parte da máquina de estados.
 */
enum OrderStatus: string
{
    case Open = 'open';
    case InAnalysis = 'in_analysis';
    case ExternalQuote = 'external_quote';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case NotApproved = 'not_approved';
    case WarrantyRepair = 'warranty_repair';
    case Completed = 'completed';
    case Canceled = 'canceled';

    /**
     * @return list<self>
     */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::Open => [self::InAnalysis, self::Canceled],
            self::InAnalysis => [self::ExternalQuote, self::AwaitingApproval, self::Canceled],
            self::ExternalQuote => [self::InAnalysis, self::AwaitingApproval, self::Canceled],
            self::AwaitingApproval => [self::Approved, self::NotApproved, self::Canceled],
            self::Approved => [self::Completed],
            self::Completed => [self::WarrantyRepair],
            self::WarrantyRepair => [self::Completed],
            self::NotApproved, self::Canceled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNextStatuses(), strict: true);
    }

    /**
     * Marcos (em dias) do alerta de "OS parada" (api#135), validado com o cliente na rodada 3 do
     * artefato de alertas. AwaitingApproval tem escada própria e mais longa — prefeitura costuma
     * demorar mais pra aprovar. Os estados finais (NotApproved, Canceled) e o pós-aprovação
     * (Completed, WarrantyRepair) não alertam: uma vez lá, não há "parado" a cobrar.
     *
     * @return list<int>
     */
    public function stalledAlertMilestoneDays(): array
    {
        return match ($this) {
            self::Open, self::InAnalysis, self::ExternalQuote, self::Approved => [7, 15, 30],
            self::AwaitingApproval => [7, 15, 30, 45, 60],
            self::NotApproved, self::Canceled, self::Completed, self::WarrantyRepair => [],
        };
    }
}
