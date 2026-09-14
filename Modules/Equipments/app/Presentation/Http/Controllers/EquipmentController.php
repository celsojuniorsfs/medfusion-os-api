<?php

namespace Modules\Equipments\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Accessories\Application\RegisterAccessory;
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
            fn () => EquipmentResource::collection(
                Equipment::with('accessories.accessory')->where('client_id', $id)->get(),
            )->toArray(request()),
        );

        return response()->json(['data' => $data]);
    }

    public function store(EquipmentRequest $request, string $id, RegisterEquipment $registerEquipment): JsonResponse
    {
        Client::findOrFail($id);

        $data = $request->validated();

        $equipment = DB::transaction(function () use ($id, $data, $registerEquipment) {
            return $registerEquipment(
                $id,
                $data['name'],
                $data['brand'],
                $data['model'],
                $data['serial_number'] ?? null,
                $data['asset_tag'] ?? null,
                $this->resolveAccessories($data),
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

        $equipment = DB::transaction(function () use ($equipmentId, $data, $updateEquipment) {
            return $updateEquipment(
                $equipmentId,
                $data['name'],
                $data['brand'],
                $data['model'],
                $data['serial_number'] ?? null,
                $data['asset_tag'] ?? null,
                $this->resolveAccessories($data),
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
     * Resolve cada entrada de `accessories` — `accessory_id` existente ou `name` novo (cadastra
     * no catálogo global na hora, mesma composição entre módulos via Presentation que
     * OrderController::resolveEquipments já faz pra equipamento). Chamado sempre dentro de uma
     * DB::transaction (ver store/update): se algo falhar no meio, nenhum acessório novo fica
     * cadastrado pela metade.
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
