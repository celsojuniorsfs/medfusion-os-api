<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Application\AddOrderItem;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\ChangeOrderEquipmentApprovalStatus;
use Modules\Orders\Application\ChangeOrderEquipmentSituation;
use Modules\Orders\Application\ChangeOrderStatus;
use Modules\Orders\Application\DeriveOrderStatusFromEquipments;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Application\OrderService;
use Modules\Orders\Application\UpdateOrder;
use Modules\Orders\Domain\Enums\OrderEquipmentApprovalStatus;
use Modules\Orders\Domain\Enums\OrderEquipmentSituation;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Infrastructure\ReadModels\OrderEquipment;
use Modules\Orders\Presentation\Http\Requests\OrderRequest;
use Modules\Orders\Presentation\Http\Resources\OrderResource;

class OrderController
{
    private const array WITH = ['client', 'user', 'equipments.accessories', 'equipments.items', 'items'];

    /**
     * O retry de contenção transitória (`ConcurrencyErrorDetector`) só funciona no nível de
     * transação MAIS EXTERNO. `OpenOrder`/`UpdateOrder` abrem a própria `DB::transaction()` por
     * dentro desta aqui (SAVEPOINT, não uma transação nova), e
     * `ManagesTransactions::handleTransactionException()` trata contenção detectada num nível
     * aninhado como fatal de propósito: relança na hora como `DeadlockException`, ignorando
     * `attempts` da transação interna. Só o `catch` da transação MAIS EXTERNA (aqui) roda de novo
     * com `attempts` valendo — passar `attempts` só na transação interna de OpenOrder/UpdateOrder
     * equivale a não ter retry nenhum, pois via HTTP ela nunca roda desaninhada.
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    /**
     * GET /orders — filtros por client_id, equipment_id (usado pela tela de histórico do
     * equipamento, ver escopo-v1.md), status e intervalo de data; ordenada por status (cancelada
     * sempre por último), depois data decrescente, depois criação decrescente (sem parâmetro de
     * sort — mesma convenção já usada em GET /clients).
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
                    // "status = 'canceled'" avalia pra 0/1 em MySQL/SQLite — ASC põe as não-
                    // canceladas antes, sem CASE. Sem índice composto pra essa expressão (aceito
                    // de propósito: filesort numa tabela pequena é mais barato que manter uma
                    // coluna gerada só pra isso).
                    ->orderByRaw("status = 'canceled'")
                    ->orderBy('date', 'desc')
                    // Desempate pra OS's da mesma `date`: sem isso a ordem entre elas fica
                    // indefinida — created_at garante que a mais nova do grupo vá primeiro.
                    ->orderBy('created_at', 'desc')
                    // `created_at` só tem precisão de segundo — duas OS's no mesmo segundo ainda
                    // empatariam. `id` como último critério não ordena por tempo (uuid), só
                    // garante que a ordem não muda entre uma consulta e outra.
                    ->orderBy('id')
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
     *
     * assertIsEditable() roda ANTES da transação, no mesmo espírito de assertNumberIsAvailable —
     * uma OS cancelada/concluída/reprovada não deve mais ser editada (ver CLAUDE.md § Recusar uma
     * remoção: cheque ANTES, nunca pelo erro do banco).
     */
    public function update(
        OrderRequest $request,
        string $id,
        OrderService $orderService,
        UpdateOrder $updateOrder,
        DeriveOrderStatusFromEquipments $deriveOrderStatus,
    ): JsonResponse {
        $order = Order::with('equipments')->findOrFail($id);

        $orderService->assertIsEditable($order);

        // Lido ANTES do UpdateOrder rodar (ele limpa e reanexa os equipamentos) — pra situação e
        // data de conclusão sobreviverem à edição, casando por equipment_id (api#140). Só entram
        // no mapa os que já têm equipment_id; sem catálogo associado sempre volta como novo.
        $previousEquipments = $order->equipments->filter(fn (OrderEquipment $e) => $e->equipment_id !== null)
            ->keyBy('equipment_id');

        $data = $request->validated();

        $order = DB::transaction(function () use ($data, $id, $updateOrder, $previousEquipments, $deriveOrderStatus) {
            $equipments = $this->resolveEquipments($data['equipments'], $data['client_id'], $previousEquipments);

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

            // Dentro da MESMA transação: reanexar pode ter adicionado/removido equipamento
            // concluído e deixado "Parcialmente concluída" desatualizada (api#140). Fora da
            // transação, uma falha aqui deixaria os equipamentos reanexados vistos com o status
            // antigo já commitado; dentro dela, o retry de TRANSACTION_ATTEMPTS também cobre isso.
            $deriveOrderStatus($id);

            return Order::findOrFail($id);
        }, self::TRANSACTION_ATTEMPTS);

        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))]);
    }

    /**
     * PATCH /orders/{id}/equipments/situation (api#140) — um id só ou vários (marcação em lote,
     * pedida pelo cliente pros lotes de prefeitura/hospital que chegam a 60 equipamentos).
     */
    public function updateEquipmentsSituation(
        Request $request,
        string $id,
        ChangeOrderEquipmentSituation $changeSituation,
    ): JsonResponse {
        Order::findOrFail($id);

        $data = $request->validate([
            'order_equipment_ids' => ['required', 'array', 'min:1'],
            'order_equipment_ids.*' => ['uuid', Rule::exists('order_equipments', 'id')->where('order_id', $id)],
            'situation' => ['required', Rule::enum(OrderEquipmentSituation::class)],
        ]);

        $order = $changeSituation($id, $data['order_equipment_ids'], OrderEquipmentSituation::from($data['situation']));

        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))]);
    }

    /**
     * PATCH /orders/{id}/equipments/approval (api#149) — um id ou vários. `whereNotNull('approval_status')`
     * no `exists` recusa (422) aprovar/reprovar um equipamento sem orçamento gerado ainda.
     */
    public function updateEquipmentsApproval(
        Request $request,
        string $id,
        ChangeOrderEquipmentApprovalStatus $changeApproval,
    ): JsonResponse {
        Order::findOrFail($id);

        $data = $request->validate([
            'order_equipment_ids' => ['required', 'array', 'min:1'],
            'order_equipment_ids.*' => [
                'uuid',
                Rule::exists('order_equipments', 'id')->where('order_id', $id)->whereNotNull('approval_status'),
            ],
            'approval_status' => ['required', Rule::enum(OrderEquipmentApprovalStatus::class)],
        ]);

        $order = $changeApproval($id, $data['order_equipment_ids'], OrderEquipmentApprovalStatus::from($data['approval_status']));

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
     * $previousEquipments (api#140) — só em edição (PUT): os `OrderEquipment` da OS ANTES desta
     * chamada, indexados por `equipment_id`, pra situação/data de conclusão sobreviverem ao
     * clearEquipments()+reattach do UpdateOrder. null em criação — não existe "anterior".
     *
     * Limitação conhecida: o casamento é por `equipment_id` do catálogo, não por
     * `order_equipments.id`. Um equipamento cadastrado implicitamente (ramo `else` abaixo) só
     * preserva a situação numa edição se o payload REENVIAR o `equipment_id` que
     * `GET /orders/{id}` devolveu — reenviar sem ele cadastra outro equipamento novo e a
     * situação reinicia, mesmo comportamento já existente pro resto do snapshot desde #45/#134.
     *
     * @param  array<int, array<string, mixed>>  $equipments
     * @return array<int, array<string, mixed>>
     */
    private function resolveEquipments(array $equipments, string $clientId, ?Collection $previousEquipments = null): array
    {
        return array_map(function (array $entry) use ($clientId, $previousEquipments) {
            if (! empty($entry['equipment_id'])) {
                // 404 (não 403) pra equipamento de outro cliente — sem o where('client_id', ...),
                // um equipment_id de OUTRO cliente virava 200 de qualquer forma.
                $equipment = Equipment::where('client_id', $clientId)->findOrFail($entry['equipment_id']);
            } else {
                // Cadastrado implicitamente, sem acessório estruturado no catálogo (o técnico
                // ajusta depois); $entry['accessories'] é uma lista digitada só pra esta OS.
                $equipment = app(RegisterEquipment::class)(
                    $clientId,
                    $entry['name'],
                    $entry['brand'] ?? null,
                    $entry['model'] ?? null,
                    $entry['serial_number'] ?? null,
                    $entry['asset_tag'] ?? null,
                );
            }

            /** @var OrderEquipment|null $previous */
            $previous = $previousEquipments?->get($equipment->id);

            return [
                'equipment_id' => $equipment->id,
                'name' => $equipment->name,
                'brand' => $equipment->brand,
                'model' => $equipment->model,
                'serial_number' => $equipment->serial_number,
                'asset_tag' => $equipment->asset_tag,
                'accessories' => $entry['accessories'] ?? [],
                'preventive_maintenance' => $entry['preventive_maintenance'] ?? false,
                'calibration' => $entry['calibration'] ?? false,
                'situation' => $previous?->situation,
                'situation_changed_at' => $previous?->situation_changed_at?->toISOString(),
                'completed_at' => $previous?->completed_at?->toISOString(),
                'approval_status' => $previous?->approval_status,
                'approval_status_changed_at' => $previous?->approval_status_changed_at?->toISOString(),
                'labor_cost' => $entry['labor_cost'] ?? null,
                'items' => $entry['items'] ?? [],
            ];
        }, $equipments);
    }

    /**
     * @param  array<int, array<string, mixed>>  $equipments  já resolvidos por resolveEquipments()
     * @param  array<int, array<string, mixed>>  $items  gerais, sem vínculo com equipamento (api#149)
     */
    private function attachEquipmentsAndItems(string $orderId, array $equipments, array $items): void
    {
        foreach ($equipments as $equipment) {
            $orderEquipmentId = (string) Str::uuid();

            app(AttachEquipmentToOrder::class)(
                $orderId,
                $equipment['equipment_id'],
                $equipment['name'],
                $equipment['brand'],
                $equipment['model'],
                $equipment['serial_number'],
                $equipment['asset_tag'],
                $equipment['accessories'],
                $equipment['preventive_maintenance'],
                $equipment['calibration'],
                $equipment['situation'],
                $equipment['situation_changed_at'],
                $equipment['completed_at'],
                $equipment['approval_status'],
                $equipment['approval_status_changed_at'],
                $equipment['labor_cost'],
                $orderEquipmentId,
            );

            foreach ($equipment['items'] as $item) {
                app(AddOrderItem::class)($orderId, $item['quantity'], $item['description'], $item['unit_price'] ?? null, $orderEquipmentId);
            }
        }

        foreach ($items as $item) {
            app(AddOrderItem::class)($orderId, $item['quantity'], $item['description'], $item['unit_price'] ?? null);
        }
    }
}
