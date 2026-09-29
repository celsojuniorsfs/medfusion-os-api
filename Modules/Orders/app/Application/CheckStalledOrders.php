<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Exceptions\InvalidOrderStatusTransition;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderStalledAlert;

/**
 * Chamada pelo comando agendado `orders:check-stalled` (api#135), uma vez por dia. O aviso é só
 * pelo painel de alertas do front-end (api#158) — este comando só grava o marco em
 * order_stalled_alerts, não manda e-mail.
 */
class CheckStalledOrders
{
    public function __construct(private readonly ChangeOrderStatus $changeOrderStatus) {}

    /**
     * @return int quantidade de marcos (milestone × OS) gravados nesta execução
     */
    public function __invoke(): int
    {
        $alertableStatuses = collect(OrderStatus::cases())
            ->filter(fn (OrderStatus $status) => $status->stalledAlertMilestoneDays() !== [])
            ->map(fn (OrderStatus $status) => $status->value)
            ->all();

        $claimed = 0;

        Order::query()
            ->whereIn('status', $alertableStatuses)
            ->whereNotNull('status_changed_at')
            ->chunkById(100, function (Collection $orders) use (&$claimed) {
                foreach ($orders as $order) {
                    // Uma OS com problema (ex.: corrida rara na constraint única) não pode
                    // derrubar o comando inteiro e deixar o resto do lote — possivelmente com
                    // marcos de verdade vencidos — sem ser verificado hoje.
                    try {
                        $claimed += $this->checkOrder($order);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $claimed;
    }

    private function checkOrder(Order $order): int
    {
        $status = OrderStatus::from($order->status);
        $daysElapsed = (int) $order->status_changed_at->diffInDays(now());
        $claimed = 0;

        foreach ($status->stalledAlertMilestoneDays() as $milestone) {
            if ($daysElapsed < $milestone || ! $this->claimMilestone($order, $milestone)) {
                continue;
            }

            // Aos 60 dias sem retorno em "aguardando aprovação", o sistema marca "Não aprovado"
            // sozinho.
            if ($status === OrderStatus::AwaitingApproval && $milestone === 60) {
                try {
                    ($this->changeOrderStatus)($order->id, OrderStatus::NotApproved);
                } catch (InvalidOrderStatusTransition $exception) {
                    // A OS já saiu de "aguardando aprovação" por fora nesse meio-tempo (ex.: o
                    // técnico aprovou antes do comando rodar) — o marco de 60 dias já ficou
                    // registrado corretamente (ela ficou parada), só a baixa automática que não
                    // se aplica mais. Não é motivo pra abortar o resto da checagem desta OS.
                    report($exception);
                }
            }

            $claimed++;
        }

        return $claimed;
    }

    /**
     * Grava o registro de idempotência — é a constraint única (order_id, status_changed_at,
     * milestone_days) que garante que este marco, pra esta passagem da OS por este status, nunca
     * é gravado duas vezes, mesmo com o cron sobrepondo (ver Schedule::withoutOverlapping() em
     * routes/console.php, que já evita isso na prática — esta constraint é a rede de segurança de
     * verdade).
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
