<?php

namespace Modules\Equipments\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Equipments\Application\RemoveEquipment;
use Modules\Equipments\Application\UpdateEquipment;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Equipments\Presentation\Http\Requests\EquipmentRequest;
use Modules\Equipments\Presentation\Http\Resources\EquipmentResource;

class EquipmentController
{
    /**
     * GET /clients/{id}/equipments — catálogo do cliente, sem paginação nem busca no servidor
     * (ver openapi.yaml): a lista inteira volta de uma vez, busca é filtro client-side no front.
     */
    public function index(string $id): JsonResponse
    {
        Client::findOrFail($id);

        // Cache de listagem (ver docs/architecture.md § Cache) — tag única do módulo (não por
        // cliente): uma escrita em qualquer equipamento invalida a listagem de todos os
        // clientes, não só do dono do evento. EquipmentUpdated/EquipmentRemoved nem carregam
        // client_id, então não dava pra invalidar granularmente sem uma consulta extra ao banco
        // dentro do Projector — listas por cliente são pequenas, o cache miss a mais não pesa.
        $data = Cache::tags(['equipments'])->remember(
            "equipments:index:{$id}",
            now()->addHour(),
            fn () => EquipmentResource::collection(Equipment::where('client_id', $id)->get())->toArray(request()),
        );

        return response()->json(['data' => $data]);
    }

    public function store(EquipmentRequest $request, string $id, RegisterEquipment $registerEquipment): JsonResponse
    {
        Client::findOrFail($id);

        $data = $request->validated();

        $equipment = $registerEquipment(
            $id,
            $data['name'],
            $data['brand'] ?? null,
            $data['model'] ?? null,
            $data['serial_number'] ?? null,
            $data['asset_tag'] ?? null,
            $data['accessories'] ?? null,
        );

        return response()->json(['data' => new EquipmentResource($equipment)], 201);
    }

    /**
     * PUT /clients/{id}/equipments/{equipmentId} — escopado por cliente: um equipamento de outro
     * cliente vira 404, não 403 (não vaza se o id existe em outro cliente).
     */
    public function update(EquipmentRequest $request, string $id, string $equipmentId, UpdateEquipment $updateEquipment): JsonResponse
    {
        Equipment::where('client_id', $id)->findOrFail($equipmentId);

        $data = $request->validated();

        $equipment = $updateEquipment(
            $equipmentId,
            $data['name'],
            $data['brand'] ?? null,
            $data['model'] ?? null,
            $data['serial_number'] ?? null,
            $data['asset_tag'] ?? null,
            $data['accessories'] ?? null,
        );

        return response()->json(['data' => new EquipmentResource($equipment)]);
    }

    public function destroy(string $id, string $equipmentId, RemoveEquipment $removeEquipment): Response
    {
        Equipment::where('client_id', $id)->findOrFail($equipmentId);

        $removeEquipment($equipmentId);

        return response()->noContent();
    }
}
