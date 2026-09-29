<?php

namespace Modules\Clients\Presentation\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Clients\Domain\Enums\PersonType;

/**
 * Valida CPF (pessoa física) ou CNPJ (pessoa jurídica). O documento exigido segue `person_type`
 * (via DataAwareRule, que dá acesso ao resto do payload) — decidir só pelo tamanho é fallback
 * defensivo caso `person_type` não venha. Pontuação é opcional; a máscara é responsabilidade do
 * frontend.
 */
class ValidTaxId implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        $personType = $this->data['person_type'] ?? null;

        $isValid = match ($personType) {
            PersonType::Individual->value => strlen($digits) === 11 && $this->isValidCpf($digits),
            PersonType::Company->value => strlen($digits) === 14 && $this->isValidCnpj($digits),
            default => match (strlen($digits)) {
                11 => $this->isValidCpf($digits),
                14 => $this->isValidCnpj($digits),
                default => false,
            },
        };

        if (! $isValid) {
            $message = match ($personType) {
                PersonType::Individual->value => 'não é um CPF válido',
                PersonType::Company->value => 'não é um CNPJ válido',
                default => 'não é um CPF ou CNPJ válido',
            };

            $fail("O :attribute informado {$message}.");
        }
    }

    private function isValidCpf(string $cpf): bool
    {
        // Sequências como "00000000000" passam pelo cálculo do dígito verificador, mas não são
        // CPFs de verdade.
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        $firstCheckDigit = $this->checkDigit(substr($cpf, 0, 9), [10, 9, 8, 7, 6, 5, 4, 3, 2]);
        $secondCheckDigit = $this->checkDigit(substr($cpf, 0, 9).$firstCheckDigit, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $cpf === substr($cpf, 0, 9).$firstCheckDigit.$secondCheckDigit;
    }

    private function isValidCnpj(string $cnpj): bool
    {
        // Sequências como "00000000000000" passam pelo cálculo do dígito verificador, mas não
        // são CNPJs de verdade.
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $firstCheckDigit = $this->checkDigit(substr($cnpj, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $secondCheckDigit = $this->checkDigit(substr($cnpj, 0, 12).$firstCheckDigit, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $cnpj === substr($cnpj, 0, 12).$firstCheckDigit.$secondCheckDigit;
    }

    /**
     * @param  list<int>  $weights
     */
    private function checkDigit(string $base, array $weights): int
    {
        $sum = 0;

        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * $weights[$i];
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }
}
