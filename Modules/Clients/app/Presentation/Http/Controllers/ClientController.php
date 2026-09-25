<?php

namespace Modules\Clients\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Clients\Application\RegisterClient;
use Modules\Clients\Application\RemoveClient;
use Modules\Clients\Application\UpdateClient;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\Clients\Presentation\Http\Requests\ClientRequest;
use Modules\Clients\Presentation\Http\Resources\ClientResource;
use Modules\Equipments\Application\RemoveEquipment;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Infrastructure\ReadModels\Order;

class ClientController
{
    /**
     * GET /clients — busca por nome/razão social, nome fantasia ou CPF/CNPJ, ordenados por
     * cadastro mais recente primeiro (ver openapi.yaml).
     */
    public function index(Request $request): JsonResponse
    {
        // Cache de listagem por versão (ver docs/architecture.md § Cache).
        $version = Cache::get('clients:cache-version', 0);
        $data = Cache::remember(
            "clients:index:v{$version}:".sha1($request->fullUrl()),
            now()->addHour(),
            function () use ($request) {
                $search = $request->query('search');
                $perPage = min(100, max(1, (int) $request->query('per_page', 15)));

                $clients = Client::query()
                    ->when($search, function ($query) use ($search) {
                        // tax_id é gravado só com dígitos (ver migration).
                        $digitsOnly = preg_replace('/\D/', '', $search);

                        $query->where(function ($q) use ($search, $digitsOnly) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('trade_name', 'like', "%{$search}%");

                            if ($digitsOnly !== '') {
                                $q->orWhere('tax_id', 'like', "%{$digitsOnly}%");
                            }
                        });
                    })
                    // Clients é event-sourced: um replay reseta created_at de todo mundo pro
                    // mesmo instante, achatando esta ordem até o próximo cadastro novo.
                    ->orderBy('created_at', 'desc')
                    ->paginate($perPage);

                // getData(true) — array, não stdClass (ver CLAUDE.md § Cache).
                return ClientResource::collection($clients)->response()->getData(true);
            },
        );

        return response()->json($data);
    }

    public function store(ClientRequest $request, RegisterClient $registerClient): JsonResponse
    {
        $client = $registerClient(...$this->attributes($request));

        return response()->json(['data' => new ClientResource($client)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => new ClientResource(Client::findOrFail($id))]);
    }

    public function update(ClientRequest $request, string $id, UpdateClient $updateClient): JsonResponse
    {
        Client::findOrFail($id);

        $client = $updateClient($id, ...$this->attributes($request));

        return response()->json(['data' => new ClientResource($client)]);
    }

    /**
     * DELETE /clients/{id} — a checagem de OS vinculada e a remoção dos equipamentos ficam aqui
     * (Presentation), não em RemoveClient: a Application de um módulo não pode importar o read
     * model de outro (ver docs/architecture.md § regra de fronteira). Cada equipamento é removido
     * pelo próprio agregado (RemoveEquipment) antes do cliente, numa transação — assim gera seu
     * próprio EquipmentRemoved em stored_events, em vez de sumir via cascadeOnDelete do banco.
     */
    public function destroy(string $id, RemoveClient $removeClient, RemoveEquipment $removeEquipment): Response|JsonResponse
    {
        Client::findOrFail($id);

        if (Order::where('client_id', $id)->exists()) {
            return response()->json([
                'message' => 'Este cliente tem Ordens de Serviço vinculadas e não pode ser removido.',
            ], 409);
        }

        DB::transaction(function () use ($id, $removeClient, $removeEquipment) {
            foreach (Equipment::where('client_id', $id)->pluck('id') as $equipmentId) {
                $removeEquipment($equipmentId);
            }

            $removeClient($id);
        });

        return response()->noContent();
    }

    /**
     * @return array<int, mixed>
     */
    private function attributes(ClientRequest $request): array
    {
        $data = $request->validated();

        return [
            PersonType::from($data['person_type']),
            $data['name'],
            $data['tax_id'],
            $data['trade_name'] ?? null,
            $data['state_registration'] ?? null,
            $data['requester'] ?? null,
            $data['department'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['address'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['postal_code'] ?? null,
        ];
    }
}
