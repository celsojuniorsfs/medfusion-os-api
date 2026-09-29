<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Status de orçamento de UM equipamento (api#149). `$from` nullable (ao contrário de
 * `OrderEquipmentSituationChanged`): a primeira transição é sempre `null → awaiting_approval`,
 * disparada por `RecordOrderPdf`, não por escolha humana.
 */
class OrderEquipmentApprovalStatusChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $orderEquipmentId,
        public readonly ?string $from,
        public readonly string $to,
    ) {}
}
