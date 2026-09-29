<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * 409, não 422 — não é erro de validação do payload. Mensagem fixa em PT-BR: nunca deriva de
 * mensagem de validação do framework, que viria em inglês.
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
