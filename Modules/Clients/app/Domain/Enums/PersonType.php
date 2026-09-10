<?php

namespace Modules\Clients\Domain\Enums;

/**
 * Feedback do Augusto (10/09/2026): parte dos clientes cadastra em nome próprio (pessoa física,
 * CPF), não com razão social/CNPJ (pessoa jurídica) — a maioria, mas não todos. Mesmo padrão do
 * `OrderStatus` (Modules\Orders\Domain\Enums): vive no Domain, mas os eventos gravam
 * `$personType->value` (string), nunca a instância do enum.
 */
enum PersonType: string
{
    case Individual = 'individual';
    case Company = 'company';
}
