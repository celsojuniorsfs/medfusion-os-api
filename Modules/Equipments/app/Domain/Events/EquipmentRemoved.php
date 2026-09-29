<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRemoved extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $photoPaths  caminhos das fotos existentes no momento da remoção,
     *                                          lidos pela Presentation antes de remover. As linhas
     *                                          de equipment_photos somem por cascade, mas os
     *                                          arquivos no disco não — o EquipmentPhotoReactor
     *                                          precisa dos caminhos aqui porque quando ele roda a
     *                                          linha já não existe mais. Com default pela mesma
     *                                          compatibilidade de replay do equipmentModelId no
     *                                          api#101 (ver CLAUDE.md).
     */
    public function __construct(
        public readonly array $photoPaths = [],
    ) {}
}
