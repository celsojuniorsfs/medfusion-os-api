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
     *                                         Duas características, cada uma com um motivo
     *                                         diferente — confirmado na marra rodando o teste de
     *                                         replay com cada variação:
     *                                         - **`?string` (nullable)** é o que mantém os eventos
     *                                         gravados antes do api#101 desserializáveis: o
     *                                         payload deles não tem essa chave, e o serializer do
     *                                         spatie passa null. Torná-lo obrigatório quebra o
     *                                         replay na hora (InvalidStoredEvent).
     *                                         - **O default `= null`** não tem a ver com
     *                                         desserialização (com nullable, o replay funciona com
     *                                         ou sem ele): é pros chamadores PHP que não passam
     *                                         modelo, como o OrderController ao cadastrar um
     *                                         equipamento implicitamente pela tela de OS.
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
