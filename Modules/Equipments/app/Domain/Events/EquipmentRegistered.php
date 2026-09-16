<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRegistered extends ShouldBeStored
{
    /**
     * @param  array<int, array{accessory_id: string, quantity: int}>  $accessories  já resolvido
     *                                                                               — accessory_id sempre presente (nome novo já foi cadastrado no catálogo antes do
     *                                                                               evento ser gravado, ver EquipmentController)
     * @param  string|null  $equipmentModelId  entrada do catálogo global de modelos (api#101).
     *                                         Último parâmetro, nullable e com default — cada
     *                                         parte importa por um motivo (matriz completa
     *                                         verificada na marra e registrada em CLAUDE.md):
     *                                         - **último**: as chamadas existentes são posicionais;
     *                                         - **nullable ou com default** (aqui, os dois): sem
     *                                         nenhum dos dois, os eventos gravados antes do
     *                                         api#101 — cujo payload não tem essa chave — param de
     *                                         desserializar num replay (InvalidStoredEvent);
     *                                         - **null especificamente**, e não outro default:
     *                                         isto é uma FK, então qualquer valor inventado
     *                                         desserializaria e só estouraria depois, no INSERT.
     *                                         `null` aqui significa "desconhecido", não "sem
     *                                         modelo" — ver EquipmentProjector, que nesse caso
     *                                         resolve pelo trio nome/marca/modelo.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        public readonly array $accessories,
        public readonly ?string $equipmentModelId = null,
    ) {}
}
