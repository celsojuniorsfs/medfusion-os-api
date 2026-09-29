<?php

namespace Modules\EquipmentModels\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipmentModelRequest extends FormRequest
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
        // name/brand/model obrigatórios: um catálogo compartilhado entre clientes fica inútil se
        // entradas nascem pela metade. Sem `unique` de propósito (mesma decisão de Accessory.name
        // e Equipment.serial_number) — o seletor do frontend evita duplicata oferecendo o que já
        // existe antes de deixar cadastrar um modelo novo.
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
        ];
    }
}
