<?php

namespace Modules\Equipments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reutilizada em store e update — o schema EquipmentInput do openapi.yaml é o mesmo pros dois.
 */
class EquipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            // Sem Rule::unique aqui de propósito: serial_number repetido no mesmo cliente não é
            // bloqueado pela API (ver Equipment no openapi.yaml) — o aviso ao técnico é
            // responsabilidade do frontend, comparando contra a lista já carregada do cliente.
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],
            'accessories' => ['nullable', 'string', 'max:255'],
        ];
    }
}
