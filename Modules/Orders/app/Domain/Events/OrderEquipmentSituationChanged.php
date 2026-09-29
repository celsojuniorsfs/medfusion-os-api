<?php

namespace Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Situação técnica de UM equipamento da OS (api#140) — independente do OrderStatusChanged da OS
 * inteira. `$orderEquipmentId` referencia a linha em `order_equipments` (mesmo id determinístico
 * de OrderEquipmentAttached::orderEquipmentId); o agregado não valida que o id pertence à OS —
 * isso é responsabilidade da Application layer (ver ChangeOrderEquipmentSituation), que lê o read
 * model antes de gravar.
 */
class OrderEquipmentSituationChanged extends ShouldBeStored
{
    public function __construct(
        public readonly string $orderEquipmentId,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
