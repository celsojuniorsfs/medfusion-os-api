<?php

namespace Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipmentAccessory;
use Modules\Orders\Infrastructure\ReadModels\OrderItem;

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
            // id da linha order_equipments (api#140) — precisa ser exposto pra frontend poder
            // referenciar um equipamento específico no PATCH /orders/{id}/equipments/situation.
            'id' => $this->id,
            'equipment_id' => $this->equipment_id,
            'name' => $this->name,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'asset_tag' => $this->asset_tag,
            'preventive_maintenance' => $this->preventive_maintenance,
            'calibration' => $this->calibration,
            // situation/position api#140 — posição vira a letra do certificado (api#61).
            'situation' => $this->situation,
            'situation_changed_at' => $this->situation_changed_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'position' => $this->position,
            // approval_status/labor_cost/items (api#149) — orçamento por equipamento, null em
            // approval_status = nenhum orçamento gerado ainda pra este equipamento.
            'approval_status' => $this->approval_status,
            'approval_status_changed_at' => $this->approval_status_changed_at?->toISOString(),
            'labor_cost' => $this->labor_cost,
            'items' => $this->items
                ->map(fn (OrderItem $item) => [
                    'quantity' => $item->quantity,
                    'description' => $item->description,
                    'unit_price' => $item->unit_price,
                ])
                ->all(),
            'accessories' => $this->accessories
                ->map(fn (OrderEquipmentAccessory $accessory) => [
                    'name' => $accessory->name,
                    'quantity' => $accessory->quantity,
                ])
                ->all(),
        ];
    }
}
