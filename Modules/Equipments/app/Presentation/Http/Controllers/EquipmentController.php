<?php

namespace Modules\Equipments\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Accessories\Application\RegisterAccessory;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\EquipmentModels\Application\RegisterEquipmentModel;
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
                $this->resolveEquipmentModel($data),
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
                $this->resolveEquipmentModel($data),
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
     * Resolve o modelo do catálogo global (api#101), mesma composição entre módulos via
     * Presentation que resolveAccessories já faz. Três caminhos, nesta ordem:
     *
     * 1. `equipment_model_id` recebido — o técnico escolheu um modelo do catálogo no seletor;
     * 2. senão, procura pelo trio exato (nome+marca+modelo) — cobre o cadastro digitado na mão
     *    que casa com uma entrada existente, e é o que segura a poluição do catálogo por
     *    duplicata sem precisar de constraint `unique` (que foi descartada de propósito);
     * 3. senão, cadastra a entrada nova no catálogo na hora.
     *
     * Sempre devolve um id: todo equipamento cadastrado daqui pra frente fica ligado ao catálogo.
     * Chamado dentro da DB::transaction de store/update — se algo falhar no meio, nenhum modelo
     * novo fica cadastrado pela metade.
     *
     * @param  array<string, mixed>  $data  validated() do EquipmentRequest
     */
    private function resolveEquipmentModel(array $data): string
    {
        if (! empty($data['equipment_model_id'])) {
            return $data['equipment_model_id'];
        }

        // Marca/modelo nunca chegam nulos aqui (EquipmentRequest exige os três), mas a comparação
        // trata nulo do mesmo jeito que o EquipmentProjector — `where('brand', null)` vira
        // `brand = NULL` em SQL e nunca casa, e essa pegadinha não deve depender de a validação
        // continuar como está.
        $existing = EquipmentModel::where('name', $data['name'])
            ->where(fn ($query) => $data['brand'] === null ? $query->whereNull('brand') : $query->where('brand', $data['brand']))
            ->where(fn ($query) => $data['model'] === null ? $query->whereNull('model') : $query->where('model', $data['model']))
            ->value('id');

        return $existing ?? app(RegisterEquipmentModel::class)($data['name'], $data['brand'], $data['model'])->id;
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
