<?php

namespace Modules\Orders\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\Orders\Infrastructure\ReadModels\Order;

/**
 * Sem ShouldQueue de propósito — não roda worker de fila em produção (ver docs/architecture.md §
 * Projectors síncronos, Reactors em fila). Enviado direto, dentro do comando agendado (api#135).
 */
class OrderStalledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly int $milestoneDays,
        public readonly bool $autoRejected = false,
    ) {}

    public function build(): self
    {
        $subject = $this->autoRejected
            ? "OS {$this->order->number} marcada como Não aprovada automaticamente"
            : "OS {$this->order->number} parada há {$this->milestoneDays} dias";

        return $this->subject($subject)
            ->markdown('orders::mail.order-stalled', [
                'order' => $this->order,
                'milestoneDays' => $this->milestoneDays,
                'autoRejected' => $this->autoRejected,
            ]);
    }
}
