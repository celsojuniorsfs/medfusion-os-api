<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * PUT /orders/{id} (api#45): mesmos campos de OrderOpened, menos userId — quem abriu a OS
 * continua sendo o dono, editar não transfere a OS pra quem está editando agora.
 */
class OrderUpdated extends ShouldBeStored
{
    public function __construct(
        public readonly int $number,
        public readonly string $date,
        public readonly string $clientId,
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
        // Por último e com default (não nullable) — eventos gravados antes do api#134 não têm
        // essas chaves no payload; replay usa false, o mesmo default do formulário.
        public readonly bool $preventiveMaintenance = false,
        public readonly bool $calibration = false,
    ) {}
}
