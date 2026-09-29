<?php

namespace Modules\Orders\Domain\Events;

use Modules\Orders\Domain\OrderEquipmentAccessories;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Payload = snapshot do equipamento no momento da criação da OS — o próprio corpo do evento é a
 * cópia congelada, não precisa de tabela/coluna "snapshot" separada.
 */
class OrderEquipmentAttached extends ShouldBeStored
{
    /**
     * @var array<int, array{name: string, quantity: int}>
     */
    public readonly array $accessories;

    /**
     * @param  string|array|null  $accessories  Antes era texto livre (?string); eventos antigos
     *                                          ainda têm string aqui e são divididos em
     *                                          {name, quantity: 1} (ver OrderEquipmentAccessories)
     *                                          — nunca estreitar de volta pra só `array` (api#108).
     * @param  ?string  $orderEquipmentId  Id da linha order_equipments, gerado na Application
     *                                     layer para replay determinístico (order_equipment_accessories
     *                                     referencia via FK). Null em eventos antigos: o projector
     *                                     cai pra um uuid novo.
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
        // Nullable, não `bool = false` (api#146): eventos antigos não têm essas chaves, e aqui
        // `null` = "não sei" (projector cai pro valor legado da OS) — distinto de "sei que é false".
        public readonly ?bool $preventiveMaintenance = null,
        public readonly ?bool $calibration = null,
        // null = sem situação anterior a preservar (equipamento novo); só preenchido ao reanexar
        // num UpdateOrder. Sem fallback pro projector, ao contrário de preventiveMaintenance
        // acima — não existe "situação legada" (api#140).
        public readonly ?string $situation = null,
        public readonly ?string $situationChangedAt = null,
        public readonly ?string $completedAt = null,
        // Mesmo padrão de situation acima, pro orçamento por equipamento (api#149).
        public readonly ?string $approvalStatus = null,
        public readonly ?string $approvalStatusChangedAt = null,
        public readonly ?float $laborCost = null,
    ) {
        $this->accessories = OrderEquipmentAccessories::normalize($accessories);
    }
}
