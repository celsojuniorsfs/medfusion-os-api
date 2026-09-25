<?php

namespace Modules\Accessories\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Accessories\Application\RegisterAccessory;
use Modules\Accessories\Application\RemoveAccessory;
use Modules\Accessories\Application\UpdateAccessory;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
use Modules\Accessories\Presentation\Http\Requests\AccessoryRequest;
use Modules\Accessories\Presentation\Http\Resources\AccessoryResource;
use Modules\Equipments\Infrastructure\ReadModels\EquipmentAccessory;

class AccessoryController
{
    /**
     * GET /accessories — catálogo global, sem paginação, busca no servidor, nem cache: lista
     * inteira, filtro client-side no seletor do front.
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

    /**
     * PUT /accessories/{id} — o nome nunca é copiado (`equipment_accessories` guarda só o id), então
     * corrigir aqui já aparece em todo equipamento que usa o acessório.
     */
    public function update(AccessoryRequest $request, string $id, UpdateAccessory $updateAccessory): JsonResponse
    {
        Accessory::findOrFail($id);

        $accessory = $updateAccessory($id, $request->validated()['name']);

        return response()->json(['data' => new AccessoryResource($accessory)]);
    }

    /**
     * DELETE /accessories/{id} — 409 quando algum equipamento usa este acessório. A checagem vem
     * ANTES de chamar a Action, nunca pela violação de FK (ver CLAUDE.md § Recusar uma remoção).
     */
    public function destroy(string $id, RemoveAccessory $removeAccessory): Response|JsonResponse
    {
        Accessory::findOrFail($id);

        if (EquipmentAccessory::where('accessory_id', $id)->exists()) {
            return response()->json([
                'message' => 'Este acessório está em uso por equipamentos cadastrados e não pode ser removido.',
            ], 409);
        }

        $removeAccessory($id);

        return response()->noContent();
    }
}
