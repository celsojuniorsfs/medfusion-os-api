<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Application\AddOrderItem;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\ChangeOrderStatus;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Application\OrderService;
use Modules\Orders\Application\UpdateOrder;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Presentation\Http\Requests\OrderRequest;
use Modules\Orders\Presentation\Http\Resources\OrderResource;

class OrderController
{
    private const array WITH = ['client', 'user', 'equipments.accessories', 'items'];

    /**
     * O retry de contenção transitória (`ConcurrencyErrorDetector` — "Lock wait timeout"/"database
     * is locked") só funciona no nível de transação MAIS EXTERNO. `OpenOrder`/`UpdateOrder` abrem a
     * própria `DB::transaction()` por dentro desta aqui (SAVEPOINT, não uma transação nova) — e
     * `Illuminate\Database\Concerns\ManagesTransactions::handleTransactionException()` trata
     * contenção detectada num nível aninhado (`$this->transactions > 1`) como fatal de propósito
     * (deadlock do MySQL desfaz a transação inteira, não só o savepoint): decrementa o contador e
     * relança na hora como `DeadlockException`, ignorando `attempts` do `DB::transaction()` interno.
     * Só o `catch` do `DB::transaction()` MAIS EXTERNO (aqui) roda com `$this->transactions === 1`
     * de novo depois desse desfazimento, e é aí que `attempts` de fato entra em ação — passar
     * `attempts` só pro `DB::transaction()` interno de `OpenOrder`/`UpdateOrder` é o mesmo que não
     * ter retry nenhum, porque na prática (via HTTP) ele nunca roda desaninhado.
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    /**
     * GET /orders — filtros por client_id, equipment_id (usado pela tela de histórico do
     * equipamento, ver escopo-v1.md), status e intervalo de data; ordenada por data decrescente
     * (sem parâmetro de sort — mesma convenção já usada em GET /clients).
     */
    public function index(Request $request): JsonResponse
    {
        // Cache de listagem por versão (ver docs/architecture.md § Cache).
        $version = Cache::get('orders:cache-version', 0);
        $data = Cache::remember(
            "orders:index:v{$version}:".sha1($request->fullUrl()),
            now()->addHour(),
            function () use ($request) {
                $perPage = min(100, max(1, (int) $request->query('per_page', 15)));

                $orders = Order::query()
                    ->with(self::WITH)
                    ->when($request->query('client_id'), fn ($q, $clientId) => $q->where('client_id', $clientId))
                    ->when(
                        $request->query('equipment_id'),
                        fn ($q, $equipmentId) => $q->whereHas('equipments', fn ($eq) => $eq->where('equipment_id', $equipmentId)),
                    )
                    ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                    ->when($request->query('date_from'), fn ($q, $date) => $q->whereDate('date', '>=', $date))
                    ->when($request->query('date_to'), fn ($q, $date) => $q->whereDate('date', '<=', $date))
                    ->orderBy('date', 'desc')
                    ->paginate($perPage);

                // getData(true) — array, não stdClass (ver CLAUDE.md § Cache).
                return OrderResource::collection($orders)->response()->getData(true);
            },
        );

        return response()->json($data);
    }

    /**
     * POST /orders — cada entrada de `equipments` sem `equipment_id` cadastra um equipamento
     * novo no catálogo do cliente nesta mesma transação (ver api-conventions.md § Equipamentos).
     * `user_id` vem sempre do usuário autenticado — não existe no payload (OrderInput).
     */
    public function store(OrderRequest $request, OrderService $orderService, OpenOrder $openOrder): JsonResponse
    {
        $data = $request->validated();

        $orderService->assertNumberIsAvailable($data['number']);

        $order = DB::transaction(function () use ($data, $request, $openOrder) {
            $equipments = $this->resolveEquipments($data['equipments'], $data['client_id']);

            $order = $openOrder(
                $data['number'],
                $data['date'],
                $data['client_id'],
                $request->user()->id,
                $data['picked_up'] ?? false,
                $data['warranty'] ?? false,
                $data['technical_training'] ?? false,
                $data['on_site_quote'] ?? false,
                $data['rental'] ?? false,
                $data['reported_defect'] ?? null,
                $data['maintenance_plan'] ?? null,
                $data['notes'] ?? null,
                $data['payment_method'] ?? null,
                $data['warranty_period'] ?? null,
                $data['proposal_validity'] ?? null,
                $data['labor_cost'] ?? null,
            );

            $this->attachEquipmentsAndItems($order->id, $equipments, $data['items'] ?? []);

            return $order;
        }, self::TRANSACTION_ATTEMPTS);

        // fresh(), não load(): load() só recarrega relações, e o total já mudou via
        // OrderProjector::onOrderItemAdded() desde que $order foi carregado.
        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => new OrderResource(Order::with(self::WITH)->findOrFail($id))]);
    }

    /**
     * PUT /orders/{id} — substitui equipamentos e peças por completo. O findOrFail() é necessário
     * porque OrderAggregate::retrieve() de um uuid desconhecido cria um agregado em branco em vez
     * de falhar, o que geraria um stored_events órfão sem nenhuma linha em `orders`.
     */
    public function update(OrderRequest $request, string $id, OrderService $orderService, UpdateOrder $updateOrder): JsonResponse
    {
        Order::findOrFail($id);

        $data = $request->validated();

        $order = DB::transaction(function () use ($data, $id, $updateOrder) {
            $equipments = $this->resolveEquipments($data['equipments'], $data['client_id']);

            $updateOrder(
                $id,
                $data['number'],
                $data['date'],
                $data['client_id'],
                $data['picked_up'] ?? false,
                $data['warranty'] ?? false,
                $data['technical_training'] ?? false,
                $data['on_site_quote'] ?? false,
                $data['rental'] ?? false,
                $data['reported_defect'] ?? null,
                $data['maintenance_plan'] ?? null,
                $data['notes'] ?? null,
                $data['payment_method'] ?? null,
                $data['warranty_period'] ?? null,
                $data['proposal_validity'] ?? null,
                $data['labor_cost'] ?? null,
            );

            $this->attachEquipmentsAndItems($id, $equipments, $data['items'] ?? []);

            return Order::findOrFail($id);
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))]);
    }

    /**
     * PATCH /orders/{id}/status — só o campo `status`, sem FormRequest à parte.
     * InvalidOrderStatusTransition já define o próprio render() (422); nada a capturar aqui.
     */
    public function updateStatus(Request $request, string $id, ChangeOrderStatus $changeOrderStatus): JsonResponse
    {
        Order::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        $order = $changeOrderStatus($id, OrderStatus::from($data['status']));

        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))]);
    }

    /**
     * GET /orders/next-number — resposta sem o envelope { data: ... } de sempre (não é um
     * recurso), formato já fixado no openapi.yaml: { "number": 1337 }.
     */
    public function nextNumber(OrderService $orderService): JsonResponse
    {
        return response()->json(['number' => $orderService->nextNumber()]);
    }

    /**
     * Resolve cada entrada de `equipments` pro formato que AttachEquipmentToOrder espera: com
     * `equipment_id`, busca o cadastro atual pra tirar o snapshot; senão, cadastra um equipamento
     * novo no catálogo do cliente.
     *
     * @param  array<int, array<string, mixed>>  $equipments
     * @return array<int, array<string, mixed>>
     */
    private function resolveEquipments(array $equipments, string $clientId): array
    {
        return array_map(function (array $entry) use ($clientId) {
            if (! empty($entry['equipment_id'])) {
                // 404 (não 403) pra equipamento de outro cliente — sem o where('client_id', ...),
                // um equipment_id de OUTRO cliente virava 200 de qualquer forma.
                $equipment = Equipment::where('client_id', $clientId)->findOrFail($entry['equipment_id']);
            } else {
                // Um equipamento cadastrado implicitamente aqui entra sem acessório estruturado
                // no catálogo (o técnico ajusta depois); $entry['accessories'] é uma lista digitada
                // pra esta OS, sem vínculo com o catálogo — vai só pro snapshot desta OS, abaixo.
                $equipment = app(RegisterEquipment::class)(
                    $clientId,
                    $entry['name'],
                    $entry['brand'] ?? null,
                    $entry['model'] ?? null,
                    $entry['serial_number'] ?? null,
                    $entry['asset_tag'] ?? null,
                );
            }

            return [
                'equipment_id' => $equipment->id,
                'name' => $equipment->name,
                'brand' => $equipment->brand,
                'model' => $equipment->model,
                'serial_number' => $equipment->serial_number,
                'asset_tag' => $equipment->asset_tag,
                'accessories' => $entry['accessories'] ?? [],
            ];
        }, $equipments);
    }

    /**
     * @param  array<int, array<string, mixed>>  $equipments  já resolvidos por resolveEquipments()
     * @param  array<int, array<string, mixed>>  $items
     */
    private function attachEquipmentsAndItems(string $orderId, array $equipments, array $items): void
    {
        foreach ($equipments as $equipment) {
            app(AttachEquipmentToOrder::class)(
                $orderId,
                $equipment['equipment_id'],
                $equipment['name'],
                $equipment['brand'],
                $equipment['model'],
                $equipment['serial_number'],
                $equipment['asset_tag'],
                $equipment['accessories'],
            );
        }

        foreach ($items as $item) {
            app(AddOrderItem::class)($orderId, $item['quantity'], $item['description'], $item['unit_price'] ?? null);
        }
    }
}
