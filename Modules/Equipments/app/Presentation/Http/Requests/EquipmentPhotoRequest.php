<?php

namespace Modules\Equipments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EquipmentPhotoRequest extends FormRequest
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
            // `image` + lista explícita de mimes: `image` sozinho aceitaria svg, que pode carregar
            // script. 8 MB cobre foto de celular sem margem exagerada.
            //
            // HEIC (padrão do iPhone) fica de fora nesta rodada de propósito — converter exigiria
            // imagick no runtime. Registrado como ressalva na issue api#102: se o técnico usar
            // iPhone e reclamar, é o primeiro ajuste a fazer.
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ];
    }
}
