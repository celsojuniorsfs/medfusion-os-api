<?php

namespace Modules\Clients\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_type' => $this->person_type,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'tax_id' => $this->tax_id,
            'state_registration' => $this->state_registration,
            'requester' => $this->requester,
            'department' => $this->department,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
