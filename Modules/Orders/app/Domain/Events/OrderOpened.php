<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * clientId e userId referenciam agregados de outros módulos (Clients, Identity) só pelo uuid —
 * Orders nunca importa Domain/Application/Infrastructure de outro módulo (ver architecture.md).
 */
class OrderOpened extends ShouldBeStored
{
    public function __construct(
        public readonly int $number,
        public readonly string $date,
        public readonly string $clientId,
        public readonly string $userId,
        public readonly bool $pickedUp,
        public readonly bool $warranty,
        public readonly bool $technicalTraining,
        public readonly bool $onSiteQuote,
        public readonly bool $rental,
        public readonly ?string $reportedDefect,
        public readonly ?string $maintenancePlan,
        public readonly ?string $notes,
        public readonly ?string $paymentMethod,
        public readonly ?string $warrantyPeriod,
        public readonly ?string $proposalValidity,
        public readonly ?float $laborCost,
    ) {}
}
