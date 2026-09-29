<?php

namespace Modules\Alerts\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Modules\Alerts\Domain\Enums\RevisionMilestone;

/**
 * Sem ShouldQueue de propósito — não roda worker de fila em produção (ver docs/architecture.md).
 * Recebe dados já resolvidos (array), não um model de outro módulo — quem monta $cycle é o
 * Command (Presentation), lendo Orders (ver CheckEquipmentRevisionsCommand).
 */
class EquipmentRevisionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{equipment_id: string, order_id: string, base_date: string, equipment_name: string, order_number: int, client_name: ?string}  $cycle
     */
    public function __construct(
        public readonly array $cycle,
        public readonly RevisionMilestone $milestone,
        public readonly Carbon $dueDate,
    ) {}

    public function build(): self
    {
        $subject = $this->milestone === RevisionMilestone::Month6
            ? "Acompanhamento: {$this->cycle['equipment_name']} (OS {$this->cycle['order_number']})"
            : "Revisão anual próxima: {$this->cycle['equipment_name']} (OS {$this->cycle['order_number']})";

        return $this->subject($subject)
            ->markdown('alerts::mail.equipment-revision', [
                'cycle' => $this->cycle,
                'milestone' => $this->milestone,
                'dueDate' => $this->dueDate,
                // Mês 11 avisa a revisão antecipando o vencimento dos 12 meses — não o próprio
                // dueDate do marco (que é 1 mês antes disso, de propósito).
                'revisionDueDate' => Carbon::parse($this->cycle['base_date'])->addMonths(12),
            ]);
    }
}
