<?php

namespace Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Modules\Orders\Infrastructure\ReadModels\OrderPdfEquipment;

class OrderPdfHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $expiresAt = now()->addMinutes(30);

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
            'url' => URL::temporarySignedRoute(
                'orders.pdf.download-historical',
                $expiresAt,
                ['id' => $this->order_id, 'pdfId' => $this->id],
            ),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
