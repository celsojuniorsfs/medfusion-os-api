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

        // Cache de listagem (ver docs/architecture.md § Cache) — versão única do módulo (não por
        // cliente): uma escrita em qualquer equipamento invalida a listagem de todos os
        // clientes, não só do dono do evento. EquipmentUpdated/EquipmentRemoved nem carregam
        // client_id, então não dava pra invalidar granularmente sem uma consulta extra ao banco
        // dentro do Projector — listas por cliente são pequenas, o cache miss a mais não pesa.
        // Padrão 0, não 1 — ver o mesmo comentário em ClientController::index (achado ao
        // reproduzir localmente exatamente este bug: cadastro sumindo da listagem).
        $version = Cache::get('equipments:cache-version', 0);
        $data = Cache::remember(
            "equipments:index:v{$version}:{$id}",
            now()->addHour(),
            // ->response()->getData(true) — array puro de verdade, não ->toArray() direto: ver
            // o mesmo comentário em ClientController::index. Achado nesta chave especificamente:
            // ->toArray() só resolve o nível de fora (a lista de recursos) — o `accessories` de
            // EquipmentResource é um ->map() sobre uma Collection, que continua sendo uma
            // Collection (objeto), não vira array puro sozinho. Guardado assim no cache, virava
            // __PHP_Incomplete_Class na volta (unserialize bloqueia objeto, ver CLAUDE.md) — o
            // equipamento voltava com accessories quebrado, o formulário de editar interpretava
            // como "sem acessórios".
            fn () => EquipmentResource::collection(
                Equipment::with('accessories.accessory')->where('client_id', $id)->get(),
            )->response()->getData(true),
        );

        return response()->json($data);
    }

    /**
     * GET /equipments/{id} — fora do prefixo /clients/{clientId} de propósito: quem chega aqui só
     * tem o uuid do equipamento (ex.: leu de um QR Code colado nele), não sabe o client_id de
     * antemão. `EquipmentResource` já devolve `client_id`, então esta rota basta pra resolver os
     * dois de uma vez.
     *
     * Sem cache (diferente do index): é busca direta por chave primária, e reaproveitar a chave de
     * cache versionada da listagem arriscaria servir um dado desatualizado sem nenhum ganho real —
     * ver CLAUDE.md § Cache pro histórico de bug com objeto incompleto vindo do cache.
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

        // A mesma validação vale pra equipamento legado sem vínculo (equipment_model_id null desde
        // sempre): editar exige escolher um modelo agora, migrando-o na hora — sem precisar de um
        // comando de migração em lote.
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
