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
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * @throws DuplicateOrderNumberException quando o número já está em uso — pelo pré-check
     *                                       (caso comum) ou pela constraint `unique` do banco
     *                                       (corrida real entre requisições simultâneas). O
     *                                       retry de contenção transitória mora no
     *                                       `DB::transaction()` mais externo do chamador
     *                                       (`OrderController::TRANSACTION_ATTEMPTS`) — passar
     *                                       `attempts` aqui não teria efeito, pois esta transação
     *                                       roda como SAVEPOINT aninhado, e o Laravel trata
     *                                       contenção nesse nível como fatal de propósito.
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
        bool $preventiveMaintenance = false,
        bool $calibration = false,
    ): Order {
        $this->orderService->assertNumberIsAvailable($number);

        $uuid = (string) Str::uuid();

        try {
            DB::transaction(function () use (
                $uuid, $number, $date, $clientId, $userId,
                $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                $reportedDefect, $maintenancePlan, $notes,
                $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
                $preventiveMaintenance, $calibration,
            ) {
                // OrderProjector roda síncrono, dentro desta mesma transação: se Order::create()
                // violar a constraint `unique` de orders.number, o rollback desfaz também o
                // insert em stored_events — sem isso, um replay futuro reprojetaria um
                // OrderOpened órfão.
                OrderAggregate::retrieve($uuid)
                    ->open(
                        $number, $date, $clientId, $userId,
                        $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                        $reportedDefect, $maintenancePlan, $notes,
                        $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
                        $preventiveMaintenance, $calibration,
                    )
                    ->persist();
            });
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateOrderNumberException;
        }

        return Order::findOrFail($uuid);
    }
}
