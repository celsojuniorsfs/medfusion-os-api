<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /orders/{id}/status pra `completed` (api#140). 422, mesmo formato de
 * InvalidOrderStatusTransition, mas exceção separada porque a transição em si é válida na tabela
 * de OrderStatus — o que barra é o estado dos equipamentos, uma checagem além da máquina de estados.
 */
class OrderHasPendingEquipments extends DomainException
{
    public function __construct()
    {
        parent::__construct('A OS tem equipamento(s) pendente(s) — conclua ou devolva sem reparo todos antes de concluir a OS.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'A transição de status solicitada não é permitida.',
            'errors' => ['status' => [$this->getMessage()]],
        ], 422);
    }
}
