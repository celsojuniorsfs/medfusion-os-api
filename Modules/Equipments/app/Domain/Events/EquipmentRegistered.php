<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRegistered extends ShouldBeStored
{
    /**
     * Já resolvido — accessory_id sempre presente (nome novo já foi cadastrado no catálogo antes
     * do evento ser gravado, ver EquipmentController).
     *
     * @var array<int, array{accessory_id: string, quantity: int}>
     */
    public readonly array $accessories;

    /**
     * @param  string|array<int, array{accessory_id: string, quantity: int}>|null  $accessories  aceita
     *                                                                                           string/null só por compatibilidade com o passado, ver o corpo do construtor
     * @param  string|null  $equipmentModelId  entrada do catálogo global de modelos (api#101).
     *                                         Nullable com default null por compatibilidade de
     *                                         replay com eventos gravados antes do api#101 (matriz
     *                                         completa em CLAUDE.md); `null` aqui significa
     *                                         "desconhecido", não "sem modelo" — EquipmentProjector
     *                                         resolve esse caso pelo trio nome/marca/modelo.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        string|array|null $accessories = [],
        public readonly ?string $equipmentModelId = null,
    ) {
        // Até o api#98, `accessories` era texto livre; eventos antigos ainda têm string aqui.
        // Aceitar string|null mantém esses eventos desserializáveis (sem isso, editar/excluir um
        // equipamento antigo estoura InvalidStoredEvent — api#108). Vira lista vazia porque o
        // api#98 já removeu a coluna de texto sem migrar dado; a conversão só alinha o evento com
        // o que a projeção já mostra.
        $this->accessories = is_array($accessories) ? $accessories : [];
    }
}
