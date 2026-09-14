<?php

namespace Modules\Accessories\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Accessories\Application\RegisterAccessory;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
use Modules\Accessories\Presentation\Http\Requests\AccessoryRequest;
use Modules\Accessories\Presentation\Http\Resources\AccessoryResource;

class AccessoryController
{
    /**
     * GET /accessories — catálogo global, sem paginação nem busca no servidor (mesma decisão já
     * tomada pro catálogo de equipamentos do cliente, ver EquipmentController::index): a lista
     * inteira volta de uma vez, busca é filtro client-side no seletor do front.
     */
    public function index(): JsonResponse
    {
        $accessories = Accessory::orderBy('name')->get();

        return response()->json(['data' => AccessoryResource::collection($accessories)]);
    }

    public function store(AccessoryRequest $request, RegisterAccessory $registerAccessory): JsonResponse
    {
        $accessory = $registerAccessory($request->validated()['name']);

        return response()->json(['data' => new AccessoryResource($accessory)], 201);
    }
}
