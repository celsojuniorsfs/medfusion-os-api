<?php

namespace Modules\Equipments\Application;

use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;

/**
 * Ao contrário de RemoveClient, não precisa checar OS vinculada antes: order_equipments.equipment_id
 * é nullOnDelete() no banco (ver migration de Orders), e o openapi.yaml só documenta 204/404/401
 * pro DELETE — remover um equipamento do catálogo nunca é bloqueado, mesmo se já usado numa OS.
 */
class RemoveEquipment
{
    public function __invoke(string $id): void
    {
        // Lê os caminhos ANTES de remover: as linhas de equipment_photos somem por cascade, mas os
        // arquivos no disco não — quem apaga é o EquipmentPhotoReactor, a partir do que o evento
        // carregar (ver EquipmentRemoved).
        $photoPaths = EquipmentPhoto::where('equipment_id', $id)->pluck('path')->all();

        EquipmentAggregate::retrieve($id)->remove($photoPaths)->persist();
    }
}
