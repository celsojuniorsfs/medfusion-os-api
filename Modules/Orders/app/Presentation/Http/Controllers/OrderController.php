<?php

namespace Modules\Orders\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Equipments\Application\RegisterEquipment;
use Modules\Equipments\Infrastructure\ReadModels\Equipment;
use Modules\Orders\Application\AddOrderItem;
use Modules\Orders\Application\AttachEquipmentToOrder;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Application\OrderService;
use Modules\Orders\Infrastructure\ReadModels\Order;
use Modules\Orders\Presentation\Http\Requests\OrderRequest;
use Modules\Orders\Presentation\Http\Resources\OrderResource;

class OrderController
{
    private const array WITH = ['client', 'user', 'equipments', 'items'];

    /**
     * GET /orders — filtros por client_id, equipment_id (usado pela tela de histórico do
     * equipamento, ver escopo-v1.md), status e intervalo de data; ordenada por data decrescente
     * (sem parâmetro de sort — mesma convenção já usada em GET /clients).
     */
    public function index(Request $request): JsonResponse
    {
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

        return response()->json(OrderResource::collection($orders)->response()->getData());
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
        });

        // fresh(), não load(): load() só recarrega as relações — os itens/equipamentos anexados
        // depois de $openOrder() também atualizaram total via OrderProjector::onOrderItemAdded(),
        // mas essa mudança nunca chega aos atributos escalares já carregados em $order.
        return response()->json(['data' => new OrderResource($order->fresh(self::WITH))], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => new OrderResource(Order::with(self::WITH)->findOrFail($id))]);
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
     * Resolve cada entrada de `equipments` do payload pro formato que AttachEquipmentToOrder
     * espera: se vier `equipment_id`, busca o cadastro atual pra tirar o snapshot; senão,
     * cadastra um equipamento novo no catálogo do cliente (RegisterEquipment, módulo Equipments
     * — Presentation pode compor entre módulos, ver docs/architecture.md).
     *
     * @param  array<int, array<string, mixed>>  $equipments
     * @return array<int, array<string, mixed>>
     */
    private function resolveEquipments(array $equipments, string $clientId): array
    {
        return array_map(function (array $entry) use ($clientId) {
            if (! empty($entry['equipment_id'])) {
                $equipment = Equipment::findOrFail($entry['equipment_id']);
            } else {
                $equipment = app(RegisterEquipment::class)(
                    $clientId,
                    $entry['name'],
                    $entry['brand'] ?? null,
                    $entry['model'] ?? null,
                    $entry['serial_number'] ?? null,
                    $entry['asset_tag'] ?? null,
                    $entry['accessories'] ?? null,
                );
            }

            return [
                'equipment_id' => $equipment->id,
                'name' => $equipment->name,
                'brand' => $equipment->brand,
                'model' => $equipment->model,
                'serial_number' => $equipment->serial_number,
                'asset_tag' => $equipment->asset_tag,
                'accessories' => $equipment->accessories,
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
