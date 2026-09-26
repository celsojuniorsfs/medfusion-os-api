<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\Orders\Domain\Enums\OrderStatus;

/**
 * PUT /orders/{id} não tinha nenhuma trava de status até esta exceção existir — uma OS
 * cancelada/concluída/reprovada podia ser editada normalmente. Mesmo estilo de render() de
 * DuplicateOrderNumberException (sem app/Exceptions/Handler neste projeto): 409, não 422 — não é
 * um erro de validação do payload, é o recurso inteiro que não aceita mais essa operação.
 */
class OrderNotEditableException extends DomainException
{
    public function __construct(OrderStatus $status)
    {
        parent::__construct("OS com status \"{$status->value}\" não pode mais ser editada.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
