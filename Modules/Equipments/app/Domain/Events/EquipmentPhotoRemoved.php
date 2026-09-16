<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentPhotoRemoved extends ShouldBeStored
{
    /**
     * `path` vai junto do id de propósito: quem apaga o arquivo é o EquipmentPhotoReactor, e nesse
     * momento a linha de equipment_photos já foi removida pelo projector (projectors rodam antes
     * dos reactors). Sem o caminho no próprio evento, o reactor não teria mais onde procurá-lo.
     */
    public function __construct(
        public readonly string $photoId,
        public readonly string $path,
    ) {}
}
