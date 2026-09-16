<?php

namespace Modules\Equipments\Application;

use Modules\Equipments\Domain\EquipmentAggregate;

class RemoveEquipmentPhoto
{
    /**
     * `$path` vem de quem chama (que já carregou a linha pra validar que a foto é daquele
     * equipamento) porque o evento precisa carregá-lo: quando o Reactor apaga o arquivo, a linha
     * de equipment_photos já não existe mais.
     */
    public function __invoke(string $equipmentId, string $photoId, string $path): void
    {
        EquipmentAggregate::retrieve($equipmentId)
            ->removePhoto($photoId, $path)
            ->persist();
    }
}
