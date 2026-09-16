<?php

namespace Modules\Accessories\Application;

use Modules\Accessories\Domain\AccessoryAggregate;

/**
 * Não verifica aqui se algum equipamento usa este acessório: exigiria importar o read model de
 * Equipments, que está acima deste módulo no grafo. A checagem é do controller (Presentation), e
 * precisa vir ANTES desta chamada — ver CLAUDE.md § Recusar uma remoção.
 */
class RemoveAccessory
{
    public function __invoke(string $id): void
    {
        AccessoryAggregate::retrieve($id)->remove()->persist();
    }
}
