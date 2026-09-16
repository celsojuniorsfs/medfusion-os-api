<?php

namespace Modules\EquipmentModels\Application;

use Modules\EquipmentModels\Domain\EquipmentModelAggregate;

/**
 * Não verifica aqui se algum equipamento usa este modelo — isso exigiria importar o read model de
 * Equipments, que está ACIMA deste módulo no grafo de dependências (ver CLAUDE.md). Quem barra é a
 * própria FK `equipments.equipment_model_id`, que é `restrictOnDelete`; o controller traduz a
 * violação em 409.
 */
class RemoveEquipmentModel
{
    public function __invoke(string $id): void
    {
        EquipmentModelAggregate::retrieve($id)->remove()->persist();
    }
}
