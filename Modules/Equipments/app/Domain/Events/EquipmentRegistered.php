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
        string|array|null $accessories = [],
        public readonly ?string $equipmentModelId = null,
    ) {
        // Até o api#98, `accessories` era texto livre (`?string`) — os eventos daquela época
        // continuam no banco com uma string aqui. Aceitar string|null é o que os mantém
        // desserializáveis: sem isso, editar ou excluir um equipamento cadastrado antes de
        // 14/09/2026 estoura InvalidStoredEvent (aconteceu em produção, ver api#107), e o
        // event-sourcing:replay nem começa.
        //
        // O texto vira lista vazia, e isso não perde nada que já não estivesse perdido: o api#98
        // removeu a coluna de texto sem migração de dado (decisão do cliente na época), então esses
        // equipamentos já aparecem sem acessório na tela. A conversão só faz o evento concordar com
        // o que a projeção mostra.
        $this->accessories = is_array($accessories) ? $accessories : [];
    }
}
