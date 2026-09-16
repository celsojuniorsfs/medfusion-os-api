<?php

namespace Modules\Equipments\Domain\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EquipmentPhotoAdded extends ShouldBeStored
{
    /**
     * O binário NÃO entra no evento — só o caminho no disco e os metadados. O arquivo é gravado
     * pela Presentation antes do evento existir (ver EquipmentPhotoController::store); o event
     * store guarda a referência, não o conteúdo.
     *
     * @param  string  $photoId  gerado na Presentation, não no projector: ao contrário dos ids de
     *                           equipment_accessories (que ninguém referencia de fora), este
     *                           aparece na URL da foto. Se o projector o gerasse, um replay
     *                           produziria ids novos e quebraria URLs já entregues.
     */
    public function __construct(
        public readonly string $photoId,
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {}
}
