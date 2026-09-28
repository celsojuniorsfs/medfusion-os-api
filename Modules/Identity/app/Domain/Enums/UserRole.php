<?php

namespace Modules\Identity\Domain\Enums;

/**
 * Fatia mínima pro sistema de alertas (api#133) mirar quem recebe cada aviso e liberar o
 * liga/desliga só pro admin geral — não é o RBAC completo do futuro perfil Financeiro (epic
 * própria). Nenhuma tela ou endpoint existente passa a checar isso. Mesmo padrão do
 * `Modules\Clients\Domain\Enums\PersonType`: vive no Domain, mas os eventos gravam `$role->value`
 * (string), nunca a instância do enum.
 */
enum UserRole: string
{
    case Technician = 'technician';
    case Administrative = 'administrative';
    case GeneralAdmin = 'general_admin';
}
