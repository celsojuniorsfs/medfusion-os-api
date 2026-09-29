<?php

namespace Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Clients\Presentation\Http\Resources\ClientResource;
use Modules\Identity\Presentation\Http\Resources\UserResource;

/**
 * Schema Order do openapi.yaml. `client`/`user` reaproveitam os Resources dos módulos donos
 * (Clients/Identity) — Presentation pode compor entre módulos (ver docs/architecture.md), e
 * evita duplicar a lista de campos de Client/User aqui. Controller sempre carrega essas relações
 * via with() antes de instanciar este Resource (ver OrderController) — nunca lazy-load.
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date?->toDateString(),
            'status' => $this->status,
            'client' => new ClientResource($this->whenLoaded('client')),
            'user' => new UserResource($this->whenLoaded('user')),
            'picked_up' => $this->picked_up,
            'warranty' => $this->warranty,
            'technical_training' => $this->technical_training,
            'on_site_quote' => $this->on_site_quote,
            'rental' => $this->rental,
            'reported_defect' => $this->reported_defect,
            'maintenance_plan' => $this->maintenance_plan,
            'notes' => $this->notes,
            'payment_method' => $this->payment_method,
            'warranty_period' => $this->warranty_period,
            'proposal_validity' => $this->proposal_validity,
            'labor_cost' => $this->labor_cost,
            'total' => $this->total,
            'certificate_number' => $this->certificate_number,
            'pdf_generated_at' => $this->pdf_generated_at,
            'equipments' => OrderEquipmentResource::collection($this->whenLoaded('equipments')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
