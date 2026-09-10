<?php

namespace Modules\Clients\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Clients\Application\RegisterClient;
use Modules\Clients\Application\RemoveClient;
use Modules\Clients\Application\UpdateClient;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Clients\Infrastructure\ReadModels\Client;
use Modules\Clients\Presentation\Http\Requests\ClientRequest;
use Modules\Clients\Presentation\Http\Resources\ClientResource;
use Modules\Orders\Infrastructure\ReadModels\Order;

class ClientController
{
    /**
     * GET /clients — busca por nome/razão social, nome fantasia ou CPF/CNPJ (ver openapi.yaml).
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 15);

        $clients = Client::query()
            ->when($search, function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('trade_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json(ClientResource::collection($clients)->response()->getData());
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
     * DELETE /clients/{id} — a checagem de OS vinculada fica aqui (Presentation), não na
     * Action: RemoveClient (Application) não pode importar o read model de Orders, de outro
     * módulo (ver docs/architecture.md § regra de fronteira).
     */
    public function destroy(string $id, RemoveClient $removeClient): Response|JsonResponse
    {
        Client::findOrFail($id);

        if (Order::where('client_id', $id)->exists()) {
            return response()->json([
                'message' => 'Este cliente tem Ordens de Serviço vinculadas e não pode ser removido.',
            ], 409);
        }

        $removeClient($id);

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
