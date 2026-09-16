<?php

namespace Modules\Equipments\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reutilizada em store e update — o schema EquipmentInput do openapi.yaml é o mesmo pros dois.
 *
 * `accessories.*` é um oneOf no contrato, mesmo padrão de `equipments.*` em OrderRequest: ou
 * `accessory_id` (referência a um acessório já cadastrado no catálogo global) ou `name` (cadastra
 * um novo). `no_accessories` existe à parte porque um array vazio sozinho não passa no
 * `required` do Laravel (trata array vazio como ausente) — sem essa flag não daria pra
 * distinguir "esqueceu de preencher" de "realmente não tem acessório nenhum" (api#92).
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
            // Sem Rule::unique aqui de propósito: serial_number repetido no mesmo cliente não é
            // bloqueado pela API (ver Equipment no openapi.yaml) — o aviso ao técnico é
            // responsabilidade do frontend, comparando contra a lista já carregada do cliente.
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],

            // Obrigatório desde o api#112 — fecha a causa-raiz da issue #110: até aqui,
            // name/brand/model chegavam como texto livre e o EquipmentController cadastrava uma
            // entrada no catálogo global sozinho quando não achava uma igual, então erro de
            // digitação e dado de teste entravam pra sempre sem ninguém pedir. Exigir o id aqui
            // significa que a ÚNICA porta de entrada do catálogo passa a ser a tela dedicada
            // (POST /equipment-models) — este endpoint só referencia, nunca mais cria.
            //
            // name/brand/model NÃO aparecem mais aqui: o front não manda mais texto livre (só
            // seleciona), e o controller lê o trio do EquipmentModel encontrado, não do payload.
            'equipment_model_id' => ['required', 'uuid', 'exists:equipment_models,id'],

            'no_accessories' => ['required', 'boolean'],
            'accessories' => ['array'],
            'accessories.*.accessory_id' => ['nullable', 'uuid', 'exists:accessories,id'],
            'accessories.*.name' => ['required_without:accessories.*.accessory_id', 'string', 'max:255'],
            'accessories.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasAccessories = collect($this->input('accessories', []))->isNotEmpty();

            if (! $this->boolean('no_accessories') && ! $hasAccessories) {
                $validator->errors()->add(
                    'accessories',
                    'Informe ao menos um acessório, ou marque que o equipamento não tem acessórios.',
                );
            }
        });
    }
}
