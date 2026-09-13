<?php

namespace Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Schema OrderEquipmentSnapshot do openapi.yaml — o retrato do equipamento no momento em que a
 * OS foi criada, não o cadastro atual (ver OrderEquipment::equipment(), no read model).
 */
class OrderEquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'equipment_id' => $this->equipment_id,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'asset_tag' => $this->asset_tag,
            'accessories' => $this->accessories,
        ];
    }
}
