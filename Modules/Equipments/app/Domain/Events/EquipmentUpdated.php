<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentUpdated extends ShouldBeStored
{
    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories  já resolvido
     *                                                                               — ver o mesmo comentário em EquipmentRegistered
     * @param  string|null  $equipmentModelId  nullable pelo mesmo motivo de
     *                                         compatibilidade de replay explicado em EquipmentRegistered (é a nulabilidade, não
     *                                         o default, que mantém os eventos antigos desserializáveis)
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        public readonly array $accessories,
        public readonly ?string $equipmentModelId = null,
    ) {}
}
