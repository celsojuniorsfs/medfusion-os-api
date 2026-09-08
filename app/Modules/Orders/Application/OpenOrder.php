<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\OrderAggregate;
use App\Modules\Orders\Infrastructure\ReadModels\Order;
use Illuminate\Support\Str;

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
