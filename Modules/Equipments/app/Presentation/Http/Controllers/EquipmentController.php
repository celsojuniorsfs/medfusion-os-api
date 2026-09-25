<?php

namespace Modules\Equipments\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Accessories\Application\RegisterAccessory;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
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

        // Cache de listagem por versão, única pro módulo inteiro, não por cliente (ver
        // docs/architecture.md § Cache).
        $version = Cache::get('equipments:cache-version', 0);
        $data = Cache::remember(
            "equipments:index:v{$version}:{$id}",
            now()->addHour(),
            // getData(true), não ->toArray(): o `accessories` de EquipmentResource é um ->map()
            // sobre Collection, que ->toArray() não resolve recursivamente (ver CLAUDE.md § Cache).
            fn () => EquipmentResource::collection(
                Equipment::with('accessories.accessory')->where('client_id', $id)->get(),
            )->response()->getData(true),
        );

        return response()->json($data);
    }

    /**
     * GET /equipments/{id} — fora do prefixo /clients/{clientId} de propósito: quem chega aqui só
     * tem o uuid do equipamento (ex.: leu de um QR Code), não sabe o client_id de antemão.
     * `EquipmentResource` já devolve `client_id`, resolvendo os dois de uma vez. Sem cache: é
     * busca direta por chave primária.
     */
    public function show(string $id): JsonResponse
    {
        $equipment = Equipment::with('accessories.accessory')->findOrFail($id);

        return response()->json(['data' => new EquipmentResource($equipment)]);
    }

    public function store(EquipmentRequest $request, string $id, RegisterEquipment $registerEquipment): JsonResponse
    {
        Client::findOrFail($id);

        $data = $request->validated();
        $model = EquipmentModel::findOrFail($data['equipment_model_id']);

        $equipment = DB::transaction(function () use ($id, $data, $model, $registerEquipment) {
            return $registerEquipment(
                $id,
                $model->name,
                $model->brand,
                $model->model,
                $data['serial_number'] ?? null,
                $data['asset_tag'] ?? null,
                $this->resolveAccessories($data),
                $model->id,
            );
        });

        return response()->json(['data' => new EquipmentResource($equipment->load('accessories.accessory'))], 201);
    }

    /**
     * PUT /clients/{id}/equipments/{equipmentId} — escopado por cliente: um equipamento de outro
     * cliente vira 404, não 403 (não vaza se o id existe em outro cliente).
     */
    public function update(EquipmentRequest $request, string $id, string $equipmentId, UpdateEquipment $updateEquipment): JsonResponse
    {
        Equipment::where('client_id', $id)->findOrFail($equipmentId);

        $data = $request->validated();
        $model = EquipmentModel::findOrFail($data['equipment_model_id']);

        $equipment = DB::transaction(function () use ($equipmentId, $data, $model, $updateEquipment) {
            return $updateEquipment(
                $equipmentId,
                $model->name,
                $model->brand,
                $model->model,
                $data['serial_number'] ?? null,
                $data['asset_tag'] ?? null,
                $this->resolveAccessories($data),
                $model->id,
            );
        });

        return response()->json(['data' => new EquipmentResource($equipment->load('accessories.accessory'))]);
    }

    public function destroy(string $id, string $equipmentId, RemoveEquipment $removeEquipment): Response
    {
        Equipment::where('client_id', $id)->findOrFail($equipmentId);

        $removeEquipment($equipmentId);

        return response()->noContent();
    }

    /**
     * Resolve cada entrada de `accessories` — `accessory_id` existente ou `name` novo, cadastrado
     * no catálogo global na hora (chamado sempre dentro da DB::transaction de store/update).
     *
     * @param  array<string, mixed>  $data  validated() do EquipmentRequest
     * @return array<int, array{accessory_id: string, quantity: int}>
     */
    private function resolveAccessories(array $data): array
    {
        return array_map(function (array $entry) {
            $accessoryId = $entry['accessory_id'] ?? app(RegisterAccessory::class)($entry['name'])->id;

            return [
                'accessory_id' => $accessoryId,
                'quantity' => $entry['quantity'],
            ];
        }, $data['accessories'] ?? []);
    }
}
