<?php

namespace Modules\EquipmentModels\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\EquipmentModels\Application\RegisterEquipmentModel;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Modules\EquipmentModels\Presentation\Http\Requests\EquipmentModelRequest;
use Modules\EquipmentModels\Presentation\Http\Resources\EquipmentModelResource;

class EquipmentModelController
{
    /**
     * GET /equipment-models — catálogo global, sem paginação nem busca no servidor (mesma decisão
     * já tomada pro catálogo de acessórios e pro catálogo de equipamentos do cliente): a lista
     * inteira volta de uma vez, busca é filtro client-side no seletor do front. Sem cache também,
     * igual AccessoryController::index — tabela pequena e append-only.
     */
    public function index(): JsonResponse
    {
        $equipmentModels = EquipmentModel::orderBy('name')->get();

        return response()->json(['data' => EquipmentModelResource::collection($equipmentModels)]);
    }

    public function store(EquipmentModelRequest $request, RegisterEquipmentModel $registerEquipmentModel): JsonResponse
    {
        $data = $request->validated();

        $equipmentModel = $registerEquipmentModel($data['name'], $data['brand'], $data['model']);

        return response()->json(['data' => new EquipmentModelResource($equipmentModel)], 201);
    }
}
