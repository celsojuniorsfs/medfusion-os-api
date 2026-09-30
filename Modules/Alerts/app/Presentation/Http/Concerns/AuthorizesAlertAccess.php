<?php

namespace Modules\Alerts\Presentation\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Identity\Domain\Enums\UserRole;

/**
 * Só administrative/general_admin veem e agem sobre alertas. Sem middleware de papel
 * reutilizável ainda no projeto (fica pra #137) — checagem inline nos controllers do módulo.
 */
trait AuthorizesAlertAccess
{
    private function assertCanManage(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, [UserRole::Administrative, UserRole::GeneralAdmin], true),
            403,
        );
    }
}
