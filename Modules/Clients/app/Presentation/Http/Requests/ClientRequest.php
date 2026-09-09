<?php

namespace Modules\Clients\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Clients\Presentation\Http\Rules\ValidCnpj;

/**
 * Reutilizada em store e update — o schema ClientInput do openapi.yaml é o mesmo pros dois.
 */
class ClientRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'max:255'],
            'tax_id' => ['required', 'string', new ValidCnpj],
            'requester' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
