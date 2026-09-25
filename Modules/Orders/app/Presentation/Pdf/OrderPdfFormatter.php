<?php

namespace Modules\Orders\Presentation\Pdf;

/**
 * Não existe nenhum formatador de CPF/CNPJ/CEP/moeda no projeto ainda — os únicos lugares que
 * lidam com esses campos hoje são o frontend (máscara no input) e a API (validação, sempre com os
 * dígitos crus). Fica na Presentation de Orders porque é o único consumidor até agora; não há
 * `Modules/Shared` neste projeto (ver docs/architecture.md § Cache, mesma observação sobre a
 * falta de um lugar comum entre módulos).
 */
class OrderPdfFormatter
{
    /**
     * CPF (11 dígitos) ou CNPJ (14) — o comprimento decide o formato, sem precisar do
     * `person_type` do cliente (que moraria noutro módulo).
     */
    public static function taxId(?string $digits): string
    {
        if (! $digits) {
            return '';
        }

        return strlen($digits) === 11
            ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits)
            : preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits);
    }

    public static function cep(?string $digits): string
    {
        if (! $digits) {
            return '';
        }

        return preg_replace('/(\d{5})(\d{3})/', '$1-$2', $digits);
    }

    public static function currency(?float $value): string
    {
        return 'R$ '.number_format($value ?? 0, 2, ',', '.');
    }
}
