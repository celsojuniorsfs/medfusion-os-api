<?php

namespace Modules\Identity\Domain\Enums;

/**
 * Fatia mínima pro sistema de alertas (api#133) mirar quem recebe cada aviso — não é o RBAC
 * completo do futuro perfil Financeiro. Os eventos gravam `$role->value` (string), nunca a
 * instância do enum.
 */
enum UserRole: string
{
    case Technician = 'technician';
    case Administrative = 'administrative';
    case GeneralAdmin = 'general_admin';
}
