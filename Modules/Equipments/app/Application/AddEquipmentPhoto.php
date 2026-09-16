<?php

namespace Modules\Equipments\Application;

use Illuminate\Support\Str;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentPhoto;

class AddEquipmentPhoto
{
    /**
     * O arquivo já está no disco quando isto roda — quem grava é o controller, que passa aqui só o
     * caminho e os metadados (ver EquipmentPhotoAdded para o porquê de o binário não entrar no
     * evento).
     *
     * O uuid da foto é gerado aqui, não no projector: ele aparece na URL da foto, e um replay que
     * gerasse ids novos quebraria URLs já entregues.
     */
    public function __invoke(
        string $equipmentId,
        string $path,
        string $originalName,
        string $mimeType,
        int $size,
    ): EquipmentPhoto {
        $photoId = (string) Str::uuid();

        EquipmentAggregate::retrieve($equipmentId)
            ->addPhoto($photoId, $path, $originalName, $mimeType, $size)
            ->persist();

        return EquipmentPhoto::findOrFail($photoId);
    }
}
