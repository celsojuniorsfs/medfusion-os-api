<?php

namespace Modules\Clients\Presentation\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Algoritmo de dígito verificador do CNPJ — aceita com ou sem pontuação (a máscara é
 * responsabilidade do frontend; a API valida o que chegar).
 */
class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if (! $this->isValid($digits)) {
            $fail('O :attribute informado não é um CNPJ válido.');
        }
    }

    private function isValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14) {
            return false;
        }

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
