<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Orders\Application\OrderService;

class OrderController
{
    /**
     * GET /orders/next-number — resposta sem o envelope { data: ... } de sempre (não é um
     * recurso), formato já fixado no openapi.yaml: { "number": 1337 }.
     */
    public function nextNumber(OrderService $orderService): JsonResponse
    {
        return response()->json(['number' => $orderService->nextNumber()]);
    }
}
