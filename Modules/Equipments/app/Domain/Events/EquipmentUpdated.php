<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentUpdated extends ShouldBeStored
{
    /**
     * Já resolvido — ver o mesmo comentário em EquipmentRegistered.
     *
     * @var array<int, array{accessory_id: string, quantity: int}>
     */
    public readonly array $accessories;

    /**
     * @param  string|array<int, array{accessory_id: string, quantity: int}>|null  $accessories  aceita
     *                                                                                           string/null pela mesma compatibilidade explicada em EquipmentRegistered
     * @param  string|null  $equipmentModelId  último parâmetro, nullable e com default, pelo mesmo
     *                                         motivo de compatibilidade de replay explicado em
     *                                         EquipmentRegistered
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        string|array|null $accessories = [],
        public readonly ?string $equipmentModelId = null,
    ) {
        // Mesmo motivo de EquipmentRegistered: antes do api#98 este campo era texto livre, e os
        // eventos daquela época precisam continuar desserializando.
        $this->accessories = is_array($accessories) ? $accessories : [];
    }
}
