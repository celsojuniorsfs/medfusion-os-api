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
        // name/brand/model obrigatórios pelo mesmo motivo que já valem em EquipmentRequest desde
        // o api#92: o técnico às vezes coloca a marca no campo do equipamento por falta de
        // organização, e um catálogo compartilhado entre todos os clientes fica inútil se as
        // entradas nascem pela metade.
        //
        // Sem `unique` de propósito, mesma decisão já documentada pro nome de Accessory e pro
        // serial_number de Equipment: o seletor do frontend evita duplicata na prática, oferecendo
        // o que já existe antes de deixar cadastrar um modelo novo.
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
        ];
    }
}
