<?php

namespace Modules\Orders\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;

/**
 * Sem ShouldQueue de propósito — não roda worker de fila em produção (ver docs/architecture.md).
 */
class OrderEquipmentSituationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OrderEquipment $equipment,
        public readonly int $milestoneDays,
    ) {}

    public function build(): self
    {
        $order = $this->equipment->order;

        return $this->subject("OS {$order->number}: {$this->equipment->name} parado há {$this->milestoneDays} dias")
            ->markdown('orders::mail.order-equipment-situation-stalled', [
                'order' => $order,
                'equipment' => $this->equipment,
                'milestoneDays' => $this->milestoneDays,
            ]);
    }
}
