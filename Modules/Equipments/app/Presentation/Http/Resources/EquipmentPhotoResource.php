<?php

namespace Modules\Equipments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class EquipmentPhotoResource extends JsonResource
{
    /**
     * `url` é montada na hora, assinada e com validade curta — não é coluna e não vai pro cache
     * (a listagem de fotos não é cacheada justamente por isso; ver EquipmentPhotoController).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'url' => URL::temporarySignedRoute(
                'equipment-photos.show',
                now()->addMinutes(30),
                ['photoId' => $this->id],
            ),
            'created_at' => $this->created_at,
        ];
    }
}
