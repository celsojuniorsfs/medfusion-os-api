<?php

namespace Modules\Alerts\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentRevisionAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'milestone' => $this->milestone,
            'due_date' => $this->due_date->toISOString(),
            'notified_at' => $this->notified_at->toISOString(),
            'client_contacted_at' => $this->client_contacted_at?->toISOString(),
            'billing_notified_at' => $this->billing_notified_at?->toISOString(),
            'equipment' => [
                'id' => $this->equipment_id,
                'name' => $this->equipment->name,
            ],
            'order' => [
                'id' => $this->order_id,
                'number' => $this->order->number,
                'client_name' => $this->order->client?->name,
            ],
        ];
    }
}
