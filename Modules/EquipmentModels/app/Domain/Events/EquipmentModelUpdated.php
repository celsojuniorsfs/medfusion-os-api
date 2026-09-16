<?php

namespace Modules\EquipmentModels\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Este evento atravessa a fronteira do módulo: o EquipmentProjector (de Equipments) reage a ele pra
 * corrigir a cópia de nome/marca/modelo que cada equipamento guarda. É o padrão sancionado em
 * docs/architecture.md — a classe de evento é o contrato público de um módulo, e Equipments pode
 * conhecer EquipmentModels (nunca o contrário).
 */
class EquipmentModelUpdated extends ShouldBeStored
{
    /**
     * Carrega também o trio ANTERIOR, e isso não é redundância: equipamentos cadastrados antes do
     * api#101 não têm o vínculo em evento nenhum — ele veio do UPDATE do comando de backfill, e o
     * EquipmentProjector o re-deriva num replay comparando nome/marca/modelo. Renomear quebraria
     * essa derivação (o trio deixa de casar), deixando o equipamento legado sem modelo e com o
     * texto antigo depois de um replay. Com o trio anterior aqui, o handler reencontra quem estava
     * ligado por derivação.
     *
     * Nullable e no fim por compatibilidade, conforme a matriz do CLAUDE.md.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $brand,
        public readonly ?string $model,
        public readonly ?string $previousName = null,
        public readonly ?string $previousBrand = null,
        public readonly ?string $previousModel = null,
    ) {}
}
