<?php

namespace Modules\Orders\Application;

use Illuminate\Support\Str;
use Modules\Orders\Domain\OrderAggregate;
use Modules\Orders\Infrastructure\ReadModels\Order;

class OpenOrder
{
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
        $uuid = (string) Str::uuid();

        OrderAggregate::retrieve($uuid)
            ->open(
                $number, $date, $clientId, $userId,
                $pickedUp, $warranty, $technicalTraining, $onSiteQuote, $rental,
                $reportedDefect, $maintenancePlan, $notes,
                $paymentMethod, $warrantyPeriod, $proposalValidity, $laborCost,
            )
            ->persist();

        return Order::findOrFail($uuid);
    }
}
