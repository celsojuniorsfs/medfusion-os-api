<?php

namespace Modules\Equipments\Application;

use Modules\Equipments\Domain\EquipmentAggregate;

/**
 * Ao contrário de RemoveClient, não precisa checar OS vinculada antes: order_equipments.equipment_id
 * é nullOnDelete() no banco (ver migration de Orders), e o openapi.yaml só documenta 204/404/401
 * pro DELETE — remover um equipamento do catálogo nunca é bloqueado, mesmo se já usado numa OS.
 */
class RemoveEquipment
{
    public function __invoke(string $id): void
    {
        EquipmentAggregate::retrieve($id)->remove()->persist();
    }
}
