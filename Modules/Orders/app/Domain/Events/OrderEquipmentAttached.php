<?php

namespace Modules\Orders\Domain\Events;

use Modules\Orders\Domain\OrderEquipmentAccessories;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Payload = o snapshot do equipamento no momento da criação da OS (decisão da F2) — o próprio
 * corpo do evento é a cópia congelada, não precisa de tabela/coluna "snapshot" separada.
 */
class OrderEquipmentAttached extends ShouldBeStored
{
    /**
     * @var array<int, array{name: string, quantity: int}>
     */
    public readonly array $accessories;

    /**
     * @param  string|array|null  $accessories  Antes desta mudança era texto livre (?string).
     *                                          Eventos antigos no stored_events ainda têm string
     *                                          aqui e são divididos em {name, quantity: 1} (ver
     *                                          OrderEquipmentAccessories) — nunca estreitar de
     *                                          volta pra só `array` (api#108, CLAUDE.md).
     * @param  ?string  $orderEquipmentId  id da linha order_equipments, gerado na Application
     *                                     layer (mesmo padrão de EquipmentPhotoAdded::photoId) pra
     *                                     replay ficar determinístico — order_equipment_accessories
     *                                     referencia esse id via FK. null em eventos gravados antes
     *                                     deste campo existir: o projector cai pra um uuid novo
     *                                     (mesmo comportamento não-determinístico de hoje, só pros
     *                                     eventos antigos).
     */
    public function __construct(
        public readonly ?string $equipmentId,
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $serialNumber,
        public readonly ?string $assetTag,
        string|array|null $accessories = [],
        public readonly ?string $orderEquipmentId = null,
        // Por último e nullable, não `bool = false` (api#146) — eventos gravados antes disso não
        // têm essas chaves, e aqui `null` precisa continuar significando "não sei" (o projector
        // cai pro valor legado da OS, ver OrderProjector::onOrderEquipmentAttached), distinto de
        // "sei que é false" num evento novo. Com `bool = false` os dois casos ficariam idênticos
        // e um `event-sourcing:replay` perderia a marcação de todo equipamento anexado antes desta
        // mudança (CLAUDE.md § Acrescentar campo a um evento já gravado).
        public readonly ?bool $preventiveMaintenance = null,
        public readonly ?bool $calibration = null,
    ) {
        $this->accessories = OrderEquipmentAccessories::normalize($accessories);
    }
}
