<?php

namespace Modules\EquipmentModels\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Evento cruza a fronteira do módulo: o EquipmentProjector (de Equipments) reage a ele para
 * corrigir a cópia de nome/marca/modelo que cada equipamento guarda — contrato público sancionado
 * em docs/architecture.md.
 */
class EquipmentModelUpdated extends ShouldBeStored
{
    /**
     * O trio ANTERIOR não é redundância: equipamentos legados sem vínculo direto no evento são
     * re-derivados pelo EquipmentProjector num replay, comparando nome/marca/modelo. Sem o trio
     * anterior aqui, renomear quebraria essa derivação. Nullable e no fim por compatibilidade
     * (ver CLAUDE.md).
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
