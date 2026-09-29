<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\Orders\Domain\Enums\OrderStatus;

/**
 * 422 no formato ValidationErrorBody do openapi.yaml — mesmo envelope de erro de validação do
 * Laravel, para uma transição de status inválida.
 */
class InvalidOrderStatusTransition extends DomainException
{
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct("Não é possível mudar a OS de \"{$from->value}\" para \"{$to->value}\".");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'A transição de status solicitada não é permitida.',
            'errors' => ['status' => [$this->getMessage()]],
        ], 422);
    }
}
