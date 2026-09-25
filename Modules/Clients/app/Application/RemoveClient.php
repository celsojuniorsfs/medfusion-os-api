<?php

namespace Modules\Clients\Application;

use Modules\Clients\Domain\ClientAggregate;

/**
 * Não verifica aqui se o cliente tem OS vinculada — isso exigiria importar o read model de
 * Orders, o que o Application de um módulo nunca faz (ver ModuleBoundariesTest e
 * docs/architecture.md). Essa checagem é do Controller (Presentation), a camada liberada pra
 * compor leitura entre módulos.
 *
 * Equipamentos do cliente: o `cascadeOnDelete` no banco continua existindo como rede de
 * segurança, mas o ClientController já remove cada equipamento pelo próprio agregado
 * (RemoveEquipment) antes de chamar esta Action — cada um gera seu EquipmentRemoved de verdade em
 * stored_events, em vez de só sumir do read model.
 */
class RemoveClient
{
    public function __invoke(string $id): void
    {
        ClientAggregate::retrieve($id)->remove()->persist();
    }
}
