<?php

namespace Modules\Alerts\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Alerts\Infrastructure\ReadModels\EquipmentRevisionAlert;

/**
 * Sem ShouldQueue de propósito — não roda worker de fila em produção (ver docs/architecture.md).
 * $alert->equipment/$alert->order.client são relações Eloquent cross-module — Infrastructure não
 * entra no ModuleBoundariesTest (ver o comentário no topo dele), mesmo padrão de OrderEquipment::equipment().
 */
class EquipmentRevisionBillingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly EquipmentRevisionAlert $alert) {}

    public function build(): self
    {
        $order = $this->alert->order;

        return $this->subject("Cobrança: revisão sem contato — {$this->alert->equipment->name} (OS {$order->number})")
            ->markdown('alerts::mail.equipment-revision-billing', [
                'alert' => $this->alert,
                'order' => $order,
            ]);
    }
}
