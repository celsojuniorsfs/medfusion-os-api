<?php

namespace Modules\Clients\Presentation\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Aceita CPF (pessoa física) ou CNPJ (pessoa jurídica) — a maioria dos clientes cadastra com
 * CNPJ e razão social, mas uma parte cadastra em nome próprio, com CPF (feedback do Augusto em
 * 10/09/2026). O dígito verificador é conferido pro documento certo conforme a quantidade de
 * dígitos (11 = CPF, 14 = CNPJ). A pontuação é opcional — a máscara é responsabilidade do
 * frontend, a API valida o que chegar.
 */
class ValidTaxId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        $isValid = match (strlen($digits)) {
            11 => $this->isValidCpf($digits),
            14 => $this->isValidCnpj($digits),
            default => false,
        };

        if (! $isValid) {
            $fail('O :attribute informado não é um CPF ou CNPJ válido.');
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
