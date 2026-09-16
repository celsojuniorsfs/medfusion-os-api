<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentRemoved extends ShouldBeStored
{
    /**
     * @param  array<int, string>  $photoPaths  caminhos das fotos que existiam no momento da
     *                                          remoção, lidos pela Presentation antes de remover.
     *                                          As linhas de equipment_photos somem sozinhas
     *                                          (cascadeOnDelete), mas os arquivos no disco não —
     *                                          é o EquipmentPhotoReactor que apaga, e ele precisa
     *                                          dos caminhos aqui porque quando roda a linha já
     *                                          não existe mais.
     *
     *                                          Nullable/com default pelo mesmo motivo do
     *                                          equipmentModelId no api#101 (ver CLAUDE.md): é a
     *                                          compatibilidade dos eventos já gravados, que não
     *                                          têm essa chave no payload.
     */
    public function __construct(
        public readonly array $photoPaths = [],
    ) {}
}
