<?php

namespace Modules\Accessories\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccessoryRequest extends FormRequest
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
        // Sem `unique` de propósito, mesma decisão já documentada pro serial_number de
        // Equipments: nomes repetidos não são bloqueados pela API — o frontend evita duplicata
        // na prática oferecendo os itens já cadastrados no seletor antes de deixar digitar um
        // novo.
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
