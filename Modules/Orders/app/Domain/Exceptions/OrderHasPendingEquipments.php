<?php

namespace Modules\Orders\Domain\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /orders/{id}/status pra `completed` (api#140) — mesmo estilo de render() de
 * InvalidOrderStatusTransition: 422, formato ValidationErrorBody do openapi.yaml. Não é a mesma
 * exceção porque a transição em si é válida na tabela de OrderStatus; o que barra é o estado dos
 * equipamentos, uma checagem além da máquina de estados (mesmo raciocínio de
 * OrderService::assertIsEditable, mas aqui mora no Domain porque a Action de status não passa
 * pela Presentation antes de decidir).
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
