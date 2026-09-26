<?php

namespace Modules\Orders\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Orders\Domain\OrderEquipmentAccessories;

/**
 * Reutilizada em store e update — o schema OrderInput do openapi.yaml é o mesmo pros dois.
 *
 * `equipments.*` é um oneOf no contrato: ou `equipment_id` (referência a um equipamento já
 * cadastrado) ou os dados de um equipamento novo (`name` obrigatório nesse caso). Laravel não
 * tem oneOf nativo — `required_without` nas duas pontas expressa a mesma regra.
 */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shim transitório: um cliente Angular ainda não atualizado manda `accessories` como string
     * livre (formato antigo) — sem isso, toda criação/edição de OS por ele passaria a estourar 422
     * assim que esta API subisse, antes do web ser deployado. Remover depois que o web atualizado
     * estiver em produção (não remover a normalização equivalente em OrderEquipmentAttached — essa
     * é permanente, protege replay de eventos já gravados).
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('equipments'))) {
            return;
        }

        $equipments = array_map(function ($equipment) {
            if (is_array($equipment) && is_string($equipment['accessories'] ?? null)) {
                $equipment['accessories'] = OrderEquipmentAccessories::fromLegacyText($equipment['accessories']);
            }

            return $equipment;
        }, $this->input('equipments'));

        $this->merge(['equipments' => $equipments]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'client_id' => ['required', 'uuid', 'exists:clients,id'],
            'picked_up' => ['boolean'],
            'warranty' => ['boolean'],
            'technical_training' => ['boolean'],
            'on_site_quote' => ['boolean'],
            'rental' => ['boolean'],
            'reported_defect' => ['nullable', 'string'],
            'maintenance_plan' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'warranty_period' => ['nullable', 'string', 'max:255'],
            'proposal_validity' => ['nullable', 'string', 'max:255'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],

            // Sem limite de quantidade — corrigido na validação de escopo (ver openapi.yaml).
            'equipments' => ['required', 'array', 'min:1'],
            'equipments.*.equipment_id' => ['nullable', 'uuid', 'exists:equipments,id'],
            'equipments.*.name' => ['required_without:equipments.*.equipment_id', 'string', 'max:255'],
            'equipments.*.brand' => ['nullable', 'string', 'max:255'],
            'equipments.*.model' => ['nullable', 'string', 'max:255'],
            'equipments.*.serial_number' => ['nullable', 'string', 'max:255'],
            'equipments.*.asset_tag' => ['nullable', 'string', 'max:255'],
            'equipments.*.accessories' => ['nullable', 'array'],
            'equipments.*.accessories.*.name' => ['required', 'string', 'max:255'],
            'equipments.*.accessories.*.quantity' => ['required', 'integer', 'min:1'],

            // Pode vir vazio — peça é opcional, ver a regra cruzada em withValidator() abaixo.
            'items' => ['array'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * A OS precisa mostrar pelo menos um valor — peças com preço e/ou mão de obra (ver
     * escopo-v1.md § Peças de reposição e mão de obra). Prefeitura costuma mandar só
     * labor_cost, com o valor da peça embutido; cliente particular costuma preencher as duas.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasLaborCost = filled($this->input('labor_cost'));
            $hasPricedItem = collect($this->input('items', []))
                ->contains(fn ($item) => filled($item['unit_price'] ?? null));

            if (! $hasLaborCost && ! $hasPricedItem) {
                $validator->errors()->add(
                    'labor_cost',
                    'Informe o valor da mão de obra ou o valor de ao menos uma peça.',
                );
            }
        });
    }
}
