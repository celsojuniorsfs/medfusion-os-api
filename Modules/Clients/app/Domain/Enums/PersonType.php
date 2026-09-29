<?php

namespace Modules\Clients\Domain\Enums;

/**
 * Cliente pode ser pessoa física (CPF) ou jurídica (CNPJ) — a maioria cadastra CNPJ, mas nem
 * todos. Os eventos gravam `$personType->value` (string), nunca a instância do enum.
 */
enum PersonType: string
{
    case Individual = 'individual';
    case Company = 'company';
}
