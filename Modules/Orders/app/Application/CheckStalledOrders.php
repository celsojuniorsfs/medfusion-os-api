<?php

namespace Modules\Orders\Application;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Infrastructure\Mail\OrderStalledMail;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderStalledAlert;

/**
 * Chamada pelo comando agendado `orders:check-stalled` (api#135), uma vez por dia — sem fila
 * (não roda worker em produção, ver docs/architecture.md). Recebe os e-mails já resolvidos
 * (quem é administrative/general_admin é decisão do módulo Identity — Application nunca importa
 * Domain/Infrastructure de outro módulo, ver docs/architecture.md; isso é composto no Command,
 * que é Presentation).
 */
class CheckStalledOrders
{
    public function __construct(private readonly ChangeOrderStatus $changeOrderStatus) {}

    /**
     * @param  list<string>  $recipientEmails
     * @return int quantidade de alertas (marco × OS) disparados nesta execução
     */
    public function __invoke(array $recipientEmails): int
    {
        if ($recipientEmails === []) {
            return 0;
        }

        $alertableStatuses = collect(OrderStatus::cases())
            ->filter(fn (OrderStatus $status) => $status->stalledAlertMilestoneDays() !== [])
            ->map(fn (OrderStatus $status) => $status->value)
            ->all();

        $sent = 0;

        Order::query()
            ->with('client')
            ->whereIn('status', $alertableStatuses)
            ->whereNotNull('status_changed_at')
            ->chunkById(100, function (Collection $orders) use ($recipientEmails, &$sent) {
                foreach ($orders as $order) {
                    $sent += $this->checkOrder($order, $recipientEmails);
                }
            });

        return $sent;
    }

    /**
     * @param  list<string>  $recipientEmails
     */
    private function checkOrder(Order $order, array $recipientEmails): int
    {
        $status = OrderStatus::from($order->status);
        $daysElapsed = (int) $order->status_changed_at->diffInDays(now());
        $sent = 0;

        foreach ($status->stalledAlertMilestoneDays() as $milestone) {
            if ($daysElapsed < $milestone || $this->alreadyNotified($order, $milestone)) {
                continue;
            }

            $isAutoRejectMilestone = $status === OrderStatus::AwaitingApproval && $milestone === 60;

            foreach ($recipientEmails as $email) {
                Mail::to($email)->send(new OrderStalledMail($order, $milestone, $isAutoRejectMilestone));
            }

            OrderStalledAlert::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'status' => $order->status,
                'status_changed_at' => $order->status_changed_at,
                'milestone_days' => $milestone,
                'notified_at' => now(),
            ]);

            // Confirmado com o cliente: aos 60 dias sem retorno em "aguardando aprovação", o
            // sistema marca "Não aprovado" sozinho — dispara depois do e-mail, pra ele já poder
            // avisar "foi marcado como não aprovado" em vez de "vai completar 60 dias".
            if ($isAutoRejectMilestone) {
                ($this->changeOrderStatus)($order->id, OrderStatus::NotApproved);
            }

            $sent++;
        }

        return $sent;
    }

    private function alreadyNotified(Order $order, int $milestone): bool
    {
        return OrderStalledAlert::query()
            ->where('order_id', $order->id)
            ->where('status_changed_at', $order->status_changed_at?->toDateTimeString())
            ->where('milestone_days', $milestone)
            ->exists();
    }
}
