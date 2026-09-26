<?php

namespace Modules\Orders\Application;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Orders\Domain\Exceptions\DuplicateOrderNumberException;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class OpenOrder
{
    /**
     * >1 pra aproveitar o retry embutido de DB::transaction() em cima de
     * ConcurrencyErrorDetector — reconhece tanto "Lock wait timeout" (MySQL) quanto "database is
     * locked" (SQLite) como contenção transitória, não erro definitivo. Sem isso, duas requisições
     * disputando o mesmo número podiam terminar numa delas travando no lock e vazando um 500 cru
     * em vez do 409 esperado (achado escrevendo o teste de corrida de verdade, api#52 — o
     * pre-check sozinho nunca fecha essa janela, só a tentativa de insert sob contenção real).
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly OrderService $orderService) {}

    /**
     * @throws DuplicateOrderNumberException quando o número já está em uso — pelo pré-check
     *                                       (caso comum) ou pela constraint `unique` do banco,
     *                                       capturada dentro da transação (corrida de verdade
     *                                       entre duas requisições simultâneas).
     */
    public function __invoke(
        int $number,
        string $date,
        string $clientId,
        string $userId,
        bool $pickedUp = false,
        bool $warranty = false,
        bool $technicalTraining = false,
        bool $onSiteQuote = false,
        bool $rental = false,
        ?string $reportedDefect = null,
        ?string $maintenancePlan = null,
        ?string $notes = null,
        ?string $paymentMethod = null,
        ?string $warrantyPeriod = null,
        ?string $proposalValidity = null,
        ?float $laborCost = null,
    ): Order {
        $this->orderService->assertNumberIsAvailable($number);

        $uuid = (string) Str::uuid();

        try {
            DB::transaction(function () use (
                $uuid, $number, $date, $clientId, $userId,
                $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                $reportedDefect, $maintenancePlan, $notes,
                $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
            ) {
                // O OrderProjector roda síncrono, dentro desta mesma transação: se Order::create()
                // disparar a violação da constraint `unique` de orders.number, o rollback desfaz
                // também o insert em stored_events (mesma conexão) — sem isso, um
                // event-sourcing:replay futuro quebraria tentando reprojetar um OrderOpened órfão.
                OrderAggregate::retrieve($uuid)
                    ->open(
                        $number, $date, $clientId, $userId,
                        $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                        $reportedDefect, $maintenancePlan, $notes,
                        $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
                    )
                    ->persist();
            }, self::TRANSACTION_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateOrderNumberException;
        }

        return Order::findOrFail($uuid);
    }
}
