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

    /**
     * PUT /accessories/{id} — corrigir o nome aqui já aparece em todo equipamento que usa o
     * acessório, porque o nome nunca foi copiado: `equipment_accessories` guarda só o id. O que
     * precisa acontecer é invalidar a listagem cacheada, e disso cuida o EquipmentProjector.
     */
    public function update(AccessoryRequest $request, string $id, UpdateAccessory $updateAccessory): JsonResponse
    {
        Accessory::findOrFail($id);

        $accessory = $updateAccessory($id, $request->validated()['name']);

        return response()->json(['data' => new AccessoryResource($accessory)]);
    }

    /**
     * DELETE /accessories/{id} — 409 quando algum equipamento usa este acessório.
     *
     * A checagem vem ANTES de chamar a Action, nunca pela violação de FK: `persist()` grava o evento
     * antes de o projector rodar, então recusar pelo erro do banco deixaria um AccessoryRemoved
     * gravado com a linha ainda existindo (ver CLAUDE.md § Recusar uma remoção). Consultar o read
     * model de Equipments aqui na Presentation é o padrão já usado por ClientController.
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
