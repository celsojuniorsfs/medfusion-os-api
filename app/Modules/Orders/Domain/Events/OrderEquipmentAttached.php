<?php

namespace App\Modules\Orders\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Payload = o snapshot do equipamento no momento da criação da OS (decisão da F2) — o próprio
 * corpo do evento é a cópia congelada, não precisa de tabela/coluna "snapshot" separada.
 */
class OrderEquipmentAttached extends ShouldBeStored
{
    public function __construct(
        public readonly ?string $equipmentId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        public readonly ?string $accessories,
    ) {}
}
