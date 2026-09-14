<?php

namespace Modules\Equipments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `accessories` precisa vir carregado (`with('accessories.accessory')`, ver EquipmentController)
 * — cada linha do pivot já traz o nome do catálogo global via a relação `accessory()`.
 */
class EquipmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'asset_tag' => $this->asset_tag,
            'accessories' => $this->accessories->map(fn ($item) => [
                'accessory_id' => $item->accessory_id,
                'name' => $item->accessory->name,
                'quantity' => $item->quantity,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
