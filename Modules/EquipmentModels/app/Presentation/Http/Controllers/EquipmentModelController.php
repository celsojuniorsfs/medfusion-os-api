<?php

namespace Modules\EquipmentModels\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\EquipmentModels\Application\RegisterEquipmentModel;
use Modules\EquipmentModels\Application\RemoveEquipmentModel;
use Modules\EquipmentModels\Application\UpdateEquipmentModel;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Modules\EquipmentModels\Presentation\Http\Requests\EquipmentModelRequest;
use Modules\EquipmentModels\Presentation\Http\Resources\EquipmentModelResource;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;

class EquipmentModelController
{
    /**
     * GET /equipment-models — catálogo global, sem paginação, busca no servidor, nem cache: lista
     * inteira, filtro client-side no seletor do front (mesma decisão de AccessoryController::index).
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

    /**
     * PUT /equipment-models/{id} — corrigir uma entrada do catálogo **também corrige os
     * equipamentos** que apontam pra ela (o EquipmentProjector reage ao evento). OS já emitidas não
     * mudam: guardam o snapshot do que foi atendido na época.
     */
    public function update(EquipmentModelRequest $request, string $id, UpdateEquipmentModel $updateEquipmentModel): JsonResponse
    {
        EquipmentModel::findOrFail($id);

        $data = $request->validated();

        $equipmentModel = $updateEquipmentModel($id, $data['name'], $data['brand'], $data['model']);

        return response()->json(['data' => new EquipmentModelResource($equipmentModel)]);
    }

    /**
     * DELETE /equipment-models/{id} — 409 quando algum equipamento usa este modelo. A checagem
     * precisa vir ANTES de chamar a Action, não depois via erro do banco: `persist()` grava o
     * evento antes do projector rodar, então recusar pela FK deixaria um EquipmentModelRemoved
     * gravado com a linha ainda existindo (ver CLAUDE.md § Recusar uma remoção). A FK continua
     * valendo como rede de segurança pra corrida entre duas requisições.
     */
    public function destroy(string $id, RemoveEquipmentModel $removeEquipmentModel): Response|JsonResponse
    {
        EquipmentModel::findOrFail($id);

        if (Equipment::where('equipment_model_id', $id)->exists()) {
            return response()->json([
                'message' => 'Este modelo está em uso por equipamentos cadastrados e não pode ser removido.',
            ], 409);
        }

        $removeEquipmentModel($id);

        return response()->noContent();
    }
}
