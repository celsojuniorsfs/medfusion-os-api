<?php

namespace Modules\Clients\Application;

use Modules\Clients\Domain\ClientAggregate;

/**
 * Não verifica OS vinculada aqui: Application não pode importar read model de outro módulo (ver
 * docs/architecture.md) — quem checa é o Controller. Equipamentos também são removidos antes,
 * pelo próprio agregado (RemoveEquipment), não pelo cascadeOnDelete do banco.
 */
class RemoveClient
{
    public function __invoke(string $id): void
    {
        ClientAggregate::retrieve($id)->remove()->persist();
    }
}
