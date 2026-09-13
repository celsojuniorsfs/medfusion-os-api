<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * Mesmo local/estilo de InvalidOrderStatusTransition. Sem app/Exceptions/Handler neste projeto
 * (sem app/ — decisão de arquitetura já conhecida, ver architecture.md), então a exceção define
 * o próprio render() em vez de precisar de um Handler global novo (mecanismo nativo do Laravel).
 *
 * Mensagem já documentada em api-conventions.md § Formato de erro e openapi.yaml (409 de
 * POST/PUT /orders) — nunca deriva de nenhuma mensagem de validação do framework, que viria em
 * inglês (ver client-form.page.ts no repo web: APP_LOCALE=pt_BR sem lang/pt_BR publicado).
 */
class DuplicateOrderNumberException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Número de OS já utilizado por outra ordem de serviço.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
