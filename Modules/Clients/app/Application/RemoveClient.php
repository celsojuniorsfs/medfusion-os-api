<?php

namespace Modules\Clients\Application;

use Modules\Clients\Domain\ClientAggregate;

/**
 * Não verifica aqui se o cliente tem OS vinculada — isso exigiria importar o read model de
 * Orders, o que o Application de um módulo nunca faz (ver ModuleBoundariesTest e
 * docs/architecture.md). Essa checagem é do Controller (Presentation), a camada liberada pra
 * compor leitura entre módulos.
 *
 * Equipamentos do cliente somem junto (cascadeOnDelete no banco) sem gerar EquipmentRemoved
 * pra cada um — lacuna conhecida, aceitável pro escopo desta v1.
 */
class RemoveClient
{
    public function __invoke(string $id): void
    {
        ClientAggregate::retrieve($id)->remove()->persist();
    }
}
