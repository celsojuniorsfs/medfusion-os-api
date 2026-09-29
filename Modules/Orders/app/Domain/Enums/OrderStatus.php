<?php

namespace Modules\Orders\Domain\Enums;

/**
 * Tabela de transições da OS (ver docs/api-conventions.md § Status da OS). O prazo de garantia
 * que limita completed → warranty_repair é regra de negócio sobre a data da OS, não sobre o
 * estado em si — não faz parte desta máquina de estados.
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
    // api#140 — "Parcialmente concluída": pelo menos um equipamento concluído/devolvido sem
    // reparo, outros ainda pendentes. Só alcançável/deixável automaticamente, ver
    // isAutomaticOnlyTransition() abaixo.
    case PartiallyCompleted = 'partially_completed';

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
            self::Approved => [self::Completed, self::PartiallyCompleted],
            self::PartiallyCompleted => [self::Completed, self::Approved],
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
     * `partially_completed` e a volta dele pra `approved` são bookkeeping derivado da situação
     * dos equipamentos (api#140, ver derivedFromEquipments() abaixo) — nunca uma decisão manual.
     * `OrderAggregate::changeStatus()` recusa essas duas transições quando não vêm marcadas como
     * automáticas (`InvalidOrderStatusTransition`, 422), mesmo estando na tabela acima (a tabela
     * só diz o que é *possível*, não *quem* pode disparar).
     */
    public function isAutomaticOnlyTransition(self $to): bool
    {
        return $to === self::PartiallyCompleted
            || ($this === self::PartiallyCompleted && $to === self::Approved);
    }

    /**
     * Deriva o status da OS a partir da situação de cada equipamento dela (api#140) — chamado
     * depois de qualquer mudança de situação de equipamento. Só se aplica quando o status atual
     * já depende dos equipamentos (`approved`, `partially_completed`, `warranty_repair`); nos
     * demais a OS não tem equipamentos "em andamento" ainda ou já são estados finais, e a função
     * não deve mexer. `null` = não muda o status.
     *
     * @param  list<OrderEquipmentSituation>  $situations  de TODOS os equipamentos da OS
     */
    public function derivedFromEquipments(array $situations): ?self
    {
        if (! in_array($this, [self::Approved, self::PartiallyCompleted, self::WarrantyRepair], true)) {
            return null;
        }

        if ($situations === []) {
            return null;
        }

        $allResolved = collect($situations)->every(fn (OrderEquipmentSituation $s) => $s->isResolved());

        // Garantia: só o equipamento reaberto interessa aqui — os outros já estão completed
        // (não passam por aqui de novo). A OS só volta pra completed quando ele também resolver;
        // não existe "warranty_repair parcial" (a OS já esteve completed antes).
        if ($this === self::WarrantyRepair) {
            return $allResolved ? self::Completed : null;
        }

        if ($allResolved) {
            return self::Completed;
        }

        $anyResolved = collect($situations)->contains(fn (OrderEquipmentSituation $s) => $s->isResolved());

        if ($this === self::Approved && $anyResolved) {
            return self::PartiallyCompleted;
        }

        if ($this === self::PartiallyCompleted && ! $anyResolved) {
            // Alguém que já tinha concluído voltou a ficar pendente (correção de status) — não é
            // o caminho esperado, mas a situação pode ir pra qualquer outra (sem máquina de
            // estados), então a OS acompanha de volta.
            return self::Approved;
        }

        return null;
    }

    /**
     * Deriva a OS pra `approved` a partir do orçamento de cada equipamento (api#149) — só quando
     * o status atual é `awaiting_approval`, disparada pelo primeiro equipamento aprovado.
     * `not_approved` por equipamento não deriva nada (é só informativo, o `not_approved` da OS
     * inteira continua pelos caminhos de sempre: baixa automática de 60 dias ou PATCH manual).
     *
     * @param  list<OrderEquipmentApprovalStatus>  $approvalStatuses  dos equipamentos com
     *                                                                orçamento já gerado (os sem
     *                                                                `approval_status` nem entram
     *                                                                aqui — não têm voto ainda)
     */
    public function derivedFromApprovals(array $approvalStatuses): ?self
    {
        if ($this !== self::AwaitingApproval) {
            return null;
        }

        $anyApproved = collect($approvalStatuses)->contains(OrderEquipmentApprovalStatus::Approved);

        return $anyApproved ? self::Approved : null;
    }

    /**
     * Marcos (em dias) do alerta de "OS parada" (api#135). AwaitingApproval tem escada própria e
     * mais longa — prefeitura costuma demorar mais pra aprovar. Estados finais e pós-aprovação
     * não alertam: uma vez lá, não há "parado" a cobrar. `PartiallyCompleted` também não entra
     * (api#140): os equipamentos pendentes já têm alerta próprio (api#147).
     *
     * @return list<int>
     */
    public function stalledAlertMilestoneDays(): array
    {
        return match ($this) {
            self::Open, self::InAnalysis, self::ExternalQuote, self::Approved => [7, 15, 30],
            self::AwaitingApproval => [7, 15, 30, 45, 60],
            self::NotApproved, self::Canceled, self::Completed, self::WarrantyRepair,
            self::PartiallyCompleted => [],
        };
    }
}
