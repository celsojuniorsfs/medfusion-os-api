<?php

namespace Modules\Alerts\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\Alerts\Domain\Enums\RevisionMilestone;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

/**
 * Sem ShouldQueue de propósito — não roda worker de fila em produção (ver docs/architecture.md).
 * Recebe dados já resolvidos (array), não um model de outro módulo — quem monta $cycle é o
 * Command (Presentation), lendo Orders (ver CheckEquipmentRevisionsCommand). Nome do equipamento
 * é a exceção: lido do catálogo aqui mesmo (não do $cycle), pro nome bater com o que
 * EquipmentRevisionBillingMail/GET /alerts/revisions mostram depois — diferente do PDF da OS
 * (histórico, nunca muda), um alerta de acompanhamento deve refletir o nome atual do catálogo.
 */
class EquipmentRevisionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{equipment_id: string, order_id: string, base_date: string, order_number: int, client_name: ?string}  $cycle
     */
    public function __construct(
        public readonly array $cycle,
        public readonly RevisionMilestone $milestone,
        public readonly Carbon $dueDate,
    ) {}

    public function build(): self
    {
        $equipmentName = Equipment::findOrFail($this->cycle['equipment_id'])->name;

        $subject = $this->milestone === RevisionMilestone::Month6
            ? "Acompanhamento: {$equipmentName} (OS {$this->cycle['order_number']})"
            : "Revisão anual próxima: {$equipmentName} (OS {$this->cycle['order_number']})";

        return $this->subject($subject)
            ->markdown('alerts::mail.equipment-revision', [
                'cycle' => $this->cycle,
                'equipmentName' => $equipmentName,
                'milestone' => $this->milestone,
                'dueDate' => $this->dueDate,
                // Mês 11 avisa a revisão antecipando o vencimento dos 12 meses — não o próprio
                // dueDate do marco (que é 1 mês antes disso, de propósito).
                'revisionDueDate' => Carbon::parse($this->cycle['base_date'])->addMonths(12),
            ]);
    }
}
