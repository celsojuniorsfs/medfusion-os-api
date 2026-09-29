<?php

namespace Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Orders\Infrastructure\ReadModels\OrderPdfEquipment;
use Modules\Orders\Presentation\Http\SignedPdfUrl;

class OrderPdfHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generated_at' => $this->generated_at->toISOString(),
            'equipments' => $this->equipments
                ->map(fn (OrderPdfEquipment $equipment) => [
                    'equipment_id' => $equipment->equipment_id,
                    'name' => $equipment->name,
                    'position' => $equipment->position,
                ])
                ->all(),
            ...SignedPdfUrl::build('orders.pdf.download-historical', ['id' => $this->order_id, 'pdfId' => $this->id]),
        ];
    }
}
