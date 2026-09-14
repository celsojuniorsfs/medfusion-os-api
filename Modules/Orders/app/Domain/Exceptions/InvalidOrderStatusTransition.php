<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\Orders\Domain\Enums\OrderStatus;

/**
 * Criada antes de existir qualquer rota que a lançasse — PATCH /orders/{id}/status (api#45)
 * é o primeiro chamador de verdade. render() no mesmo estilo de DuplicateOrderNumberException
 * (sem app/Exceptions/Handler neste projeto), mas no formato ValidationErrorBody do openapi.yaml
 * (422 pra transição de status inválida usa o mesmo envelope de erro de validação do Laravel).
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
