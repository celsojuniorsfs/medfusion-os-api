<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
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
                    // Uma OS com problema (ex.: e-mail que estoura, corrida rara na constraint
                    // única) não pode derrubar o comando inteiro e deixar o resto do lote —
                    // possivelmente com marcos de verdade vencidos — sem ser verificado hoje.
                    try {
                        $sent += $this->checkOrder($order, $recipientEmails);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
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
            if ($daysElapsed < $milestone || ! $this->claimMilestone($order, $milestone)) {
                continue;
            }

            $isAutoRejectMilestone = $status === OrderStatus::AwaitingApproval && $milestone === 60;

            foreach ($recipientEmails as $email) {
                Mail::to($email)->send(new OrderStalledMail($order, $milestone, $isAutoRejectMilestone));
            }

            // Confirmado com o cliente: aos 60 dias sem retorno em "aguardando aprovação", o
            // sistema marca "Não aprovado" sozinho — dispara depois do e-mail, pra ele já poder
            // avisar "foi marcado como não aprovado" em vez de "vai completar 60 dias".
            if ($isAutoRejectMilestone) {
                try {
                    ($this->changeOrderStatus)($order->id, OrderStatus::NotApproved);
                } catch (InvalidOrderStatusTransition $exception) {
                    // A OS já saiu de "aguardando aprovação" por fora nesse meio-tempo (ex.: o
                    // técnico aprovou antes do comando rodar) — o e-mail de aviso já saiu
                    // corretamente (ela ficou 60 dias parada), só a baixa automática que não se
                    // aplica mais. Não é motivo pra abortar o resto da checagem desta OS.
                    report($exception);
                }
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * Grava o registro de idempotência ANTES de mandar o e-mail, não depois — é a constraint
     * única (order_id, status_changed_at, milestone_days) que garante que este marco, pra esta
     * passagem da OS por este status, nunca dispara duas vezes, mesmo com o cron sobrepondo (ver
     * Schedule::withoutOverlapping() em routes/console.php, que já evita isso na prática — esta
     * constraint é a rede de segurança de verdade). "Marca e depois manda" também limita o pior
     * caso de uma falha no envio a um marco perdido (contido), em vez de reenviar o mesmo e-mail
     * pra sempre a cada execução do comando enquanto o marco nunca for gravado como notificado.
     */
    private function claimMilestone(Order $order, int $milestone): bool
    {
        try {
            OrderStalledAlert::create([
                'id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'status' => $order->status,
                'status_changed_at' => $order->status_changed_at,
                'milestone_days' => $milestone,
                'notified_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
