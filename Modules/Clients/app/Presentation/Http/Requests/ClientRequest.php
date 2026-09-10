<?php

namespace Modules\Clients\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Presentation\Http\Rules\ValidTaxId;

/**
 * Reutilizada em store e update — o schema ClientInput do openapi.yaml é o mesmo pros dois.
 */
class ClientRequest extends FormRequest
{
    /**
     * As 27 siglas de UF — lista de referência estática, sem regra de negócio associada (ao
     * contrário de PersonType, que tem comportamento). Não vira enum por isso; fica só aqui.
     */
    private const BRAZILIAN_STATES = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB',
        'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * CPF/CNPJ e CEP chegam do frontend com pontuação (é como o usuário digita e vê na tela) —
     * o banco guarda só dígitos (ver migration de clients), então normaliza antes das regras
     * rodarem: ValidTaxId, o `unique` de tax_id e o `digits:8` de postal_code já operam sobre o
     * valor limpo, que é o que chega no Aggregate/evento/read model.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_id' => preg_replace('/\D/', '', (string) $this->input('tax_id')),
            'postal_code' => $this->input('postal_code') !== null
                ? preg_replace('/\D/', '', (string) $this->input('postal_code'))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'person_type' => ['required', Rule::enum(PersonType::class)],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => [
                'required', 'string', new ValidTaxId,
                Rule::unique('clients', 'tax_id')->ignore($this->route('id')),
            ],
            'state_registration' => ['nullable', 'string', 'max:255'],
            'requester' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2', Rule::in(self::BRAZILIAN_STATES)],
            'postal_code' => ['nullable', 'digits:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_id.unique' => 'Este CPF/CNPJ já está cadastrado.',
        ];
    }
}
