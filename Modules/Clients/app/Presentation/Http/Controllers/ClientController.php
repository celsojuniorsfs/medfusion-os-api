<?php

namespace Modules\Clients\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $search = $request->query('search');

        // Achado do code review de 13/09/2026: já documentado em openapi.yaml (parâmetro
        // PerPage: minimum 1, maximum 100) mas nunca aplicado aqui — um per_page=999999 (ou 0/
        // negativo) passava direto pro paginate(). Clamp deixa o código fiel ao contrato.
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));

        $clients = Client::query()
            ->when($search, function ($query) use ($search) {
                // tax_id é gravado só com dígitos (ver migration) — buscar "111.444.777-35" como
                // o usuário vê na tela não bateria com "11144477735" sem essa normalização.
                $digitsOnly = preg_replace('/\D/', '', $search);

                $query->where(function ($q) use ($search, $digitsOnly) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('trade_name', 'like', "%{$search}%");

                    if ($digitsOnly !== '') {
                        $q->orWhere('tax_id', 'like', "%{$digitsOnly}%");
                    }
                });
            })
            // Mais recentes primeiro (F-mobile, 12/09/2026) — sem parâmetro de sort na API de
            // propósito (mesma convenção já documentada pro futuro endpoint de Orders): a ordem
            // certa é o próprio default, não algo que o cliente da API precise pedir. Ressalva:
            // como Clients é event-sourced, created_at é quando o Projector escreveu a linha — um
            // event-sourcing:replay reseta todo mundo pro mesmo instante, achatando a ordem até o
            // próximo cadastro novo.
            ->orderBy('created_at', 'desc')
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
     *
     * Achado do code review de 13/09/2026: RemoveClient documentava como "lacuna conhecida" o
     * fato de equipamentos do cliente sumirem via cascadeOnDelete do banco sem gerar
     * EquipmentRemoved nenhum — stored_events (a fonte da verdade auditável, ver
     * architecture.md) ficava sem registro de por que aqueles equipamentos desapareceram. Corrige
     * aqui, não em RemoveClient, pelo mesmo motivo do 409 acima: é a camada liberada a compor
     * módulos. Cada equipamento é removido pelo próprio agregado (RemoveEquipment, do módulo
     * Equipments — chamar a Application de outro módulo a partir da Presentation não viola a
     * regra de fronteira, que só proíbe Domain/Application chamando outro Domain/Application)
     * antes do cliente, numa transação: se algo falhar no meio, nada fica removido pela metade.
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
