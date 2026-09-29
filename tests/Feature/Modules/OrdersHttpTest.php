<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\Equipments\Domain\EquipmentAggregate;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Orders\Application\ChangeOrderStatus;
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Enums\OrderStatus;
use Modules\Orders\Domain\Events\OrderEquipmentAttached;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderItemsCleared;
use Modules\Orders\Infrastructure\Projectors\OrderProjector;
use Spatie\EventSourcing\Facades\Projectionist;
use Tests\TestCase;

class OrdersHttpTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', 'ana@medfusion.example', Hash::make('segredo'))
            ->persist();

        return User::findOrFail($uuid);
    }

    private function aClientId(string $taxId = '31233218000110'): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: $taxId,
                tradeName: null,
                stateRegistration: null,
                requester: null,
                department: null,
                phone: null,
                email: null,
                address: null,
                city: null,
                state: null,
                postalCode: null,
            )
            ->persist();

        return $uuid;
    }

    private function openOrder(int $number, string $clientId, string $userId): void
    {
        app(OpenOrder::class)(
            $number, '2026-09-08', $clientId, $userId,
            false, false, false, false, false, null, null, null, null, null, null, null,
        );
    }

    private function anEquipmentId(string $clientId, string $name = 'Bisturi'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, $name, 'Marca X', null, 'SN-123', null, [])
            ->persist();

        return $uuid;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalOrderPayload(string $clientId, int $number = 1337): array
    {
        return [
            'number' => $number,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 150.0,
            'equipments' => [
                ['name' => 'Bisturi Elétrico'],
            ],
            'items' => [],
        ];
    }

    /**
     * api#140 — atalho pra chegar em `approved` (open → in_analysis → awaiting_approval →
     * approved), a tabela de transições não tem um caminho mais curto.
     */
    private function approveOrder(User $user, string $orderId): void
    {
        foreach (['in_analysis', 'awaiting_approval', 'approved'] as $status) {
            $this->actingAs($user, 'sanctum')
                ->patchJson("/api/v1/orders/{$orderId}/status", ['status' => $status])
                ->assertOk();
        }
    }

    public function test_guests_cannot_access_the_next_number_endpoint(): void
    {
        $this->getJson('/api/v1/orders/next-number')->assertStatus(401);
    }

    public function test_suggests_1337_when_the_orders_table_is_empty(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1337]);
    }

    public function test_suggests_the_last_number_plus_one_after_an_order_is_opened(): void
    {
        $user = $this->authenticatedUser();
        $this->openOrder(1400, $this->aClientId(), $user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1401]);
    }

    public function test_a_gap_left_by_an_overridden_number_stays_free(): void
    {
        // Se o técnico sobrescreveu a sugestão pra um número bem maior, os números pulados ficam
        // permanentemente livres — next-number nunca "preenche" a lacuna, só reflete o MAX() atual
        // (ver api-conventions.md § Concorrência na numeração da OS).
        $user = $this->authenticatedUser();
        $this->openOrder(1500, $this->aClientId(), $user->id);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders/next-number');

        $response->assertOk();
        $response->assertExactJson(['number' => 1501]);
    }

    public function test_guests_cannot_access_order_endpoints(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401);
        $this->postJson('/api/v1/orders', [])->assertStatus(401);
    }

    public function test_creates_an_order_with_a_new_equipment_and_no_items(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId));

        $response->assertCreated();
        $response->assertJsonPath('data.number', 1337);
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.client.id', $clientId);
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.total', '150.00');
        $response->assertJsonCount(1, 'data.equipments');
        $response->assertJsonPath('data.equipments.0.name', 'Bisturi Elétrico');
        $response->assertJsonCount(0, 'data.items');

        // O equipamento digitado na hora entra pro catálogo do cliente (ver api-conventions.md
        // § Equipamentos) — próxima OS já pode reaproveitá-lo por equipment_id.
        $this->assertDatabaseHas('equipments', ['client_id' => $clientId, 'name' => 'Bisturi Elétrico']);
    }

    public function test_creates_an_order_with_an_equipment_marked_as_preventive_maintenance_and_calibration(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'][0]['preventive_maintenance'] = true;
        $payload['equipments'][0]['calibration'] = true;

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.equipments.0.preventive_maintenance', true);
        $response->assertJsonPath('data.equipments.0.calibration', true);
        $this->assertDatabaseHas('order_equipments', [
            'name' => 'Bisturi Elétrico',
            'preventive_maintenance' => true,
            'calibration' => true,
        ]);
    }

    public function test_creating_an_order_without_preventive_or_calibration_defaults_to_false(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId));

        $response->assertCreated();
        $response->assertJsonPath('data.equipments.0.preventive_maintenance', false);
        $response->assertJsonPath('data.equipments.0.calibration', false);
    }

    public function test_equipments_in_the_same_order_can_have_different_preventive_and_calibration_flags(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [
            ['name' => 'Monitor', 'preventive_maintenance' => true, 'calibration' => true],
            ['name' => 'Mesa cirúrgica', 'preventive_maintenance' => true, 'calibration' => false],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        // Por nome, não por índice — a relação equipments() não garante a ordem de retorno.
        $equipments = collect($response->json('data.equipments'))->keyBy('name');
        $this->assertTrue($equipments['Monitor']['calibration']);
        $this->assertFalse($equipments['Mesa cirúrgica']['calibration']);
        $this->assertTrue($equipments['Mesa cirúrgica']['preventive_maintenance']);
    }

    public function test_creates_an_order_referencing_an_existing_equipment_and_with_items(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId, 'Monitor Cardíaco');

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'equipments' => [['equipment_id' => $equipmentId]],
            'items' => [
                ['quantity' => 2, 'description' => 'Cabo de força', 'unit_price' => 25.0],
                ['quantity' => 1, 'description' => 'Sensor', 'unit_price' => null],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.equipments.0.equipment_id', $equipmentId);
        $response->assertJsonPath('data.equipments.0.name', 'Monitor Cardíaco');
        $response->assertJsonPath('data.equipments.0.serial_number', 'SN-123');
        $response->assertJsonCount(2, 'data.items');
        // total = 2 * 25 (o item sem unit_price não soma nada) = 50, sem labor_cost.
        $response->assertJsonPath('data.total', '50.00');
    }

    /**
     * Trava o contrato documentado em `docs/openapi.yaml::OrderEquipmentInput`: `accessories` é
     * uma lista de `{name, quantity}` e vale junto com `equipment_id` — é o snapshot desta OS
     * (`order_equipment_accessories`), independente do catálogo estruturado de acessórios do
     * equipamento.
     */
    public function test_keeps_the_os_level_accessories_list_when_referencing_an_existing_equipment(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId, 'Monitor Cardíaco');

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 100.0,
            'equipments' => [[
                'equipment_id' => $equipmentId,
                'accessories' => [
                    ['name' => 'Cabo de força', 'quantity' => 2],
                    ['name' => 'Pedal', 'quantity' => 1],
                ],
            ]],
            'items' => [],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.equipments.0.equipment_id', $equipmentId);
        $response->assertJsonCount(2, 'data.equipments.0.accessories');
        $response->assertJsonPath('data.equipments.0.accessories.0.name', 'Cabo de força');
        $response->assertJsonPath('data.equipments.0.accessories.0.quantity', 2);
        $response->assertJsonPath('data.equipments.0.accessories.1.name', 'Pedal');

        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('order_equipment_accessories', [
            'name' => 'Cabo de força', 'quantity' => 2, 'position' => 0,
        ]);
        $this->assertDatabaseHas('order_equipment_accessories', [
            'name' => 'Pedal', 'quantity' => 1, 'position' => 1,
        ]);
        $this->assertSame(1, DB::table('order_equipments')->where('order_id', $orderId)->count());
    }

    /**
     * Shim transitório em `OrderRequest::prepareForValidation()` (ver docs/openapi.yaml) — um
     * cliente ainda não atualizado pro novo formato manda `accessories` como string livre, e a API
     * aceita e divide em vez de estourar 422. Remover este teste junto com o shim.
     */
    public function test_accepts_the_legacy_free_text_accessories_shape_and_splits_it(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId, 'Monitor Cardíaco');

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 100.0,
            'equipments' => [['equipment_id' => $equipmentId, 'accessories' => 'Cabo de força; pedal']],
            'items' => [],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.equipments.0.accessories');
        $response->assertJsonPath('data.equipments.0.accessories.0.name', 'Cabo de força');
        $response->assertJsonPath('data.equipments.0.accessories.0.quantity', 1);
        $response->assertJsonPath('data.equipments.0.accessories.1.name', 'pedal');
    }

    public function test_rejects_an_accessory_without_a_name(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'][0]['accessories'] = [['quantity' => 1]];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['equipments.0.accessories.0.name']);
    }

    public function test_rejects_an_accessory_with_zero_quantity(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'][0]['accessories'] = [['name' => 'Pedal', 'quantity' => 0]];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['equipments.0.accessories.0.quantity']);
    }

    /**
     * PUT substitui a lista de equipamentos inteira (OrderEquipmentsCleared) — as linhas de
     * acessórios do equipamento antigo precisam sumir junto (cascade em
     * order_equipment_accessories.order_equipment_id), não sobreviver órfãs.
     */
    public function test_replacing_equipments_on_update_removes_the_old_accessories(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 100.0,
            'equipments' => [['name' => 'Bisturi', 'accessories' => [['name' => 'Cabo', 'quantity' => 1]]]],
            'items' => [],
        ])->json('data');

        $this->assertSame(1, DB::table('order_equipment_accessories')->count());

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 100.0,
            'equipments' => [['name' => 'Pinça', 'accessories' => []]],
            'items' => [],
        ]);

        $response->assertOk();
        $this->assertSame(0, DB::table('order_equipment_accessories')->count());
    }

    /**
     * Eventos gravados antes desta mudança têm `accessories` como string e não têm
     * `orderEquipmentId` no payload — o construtor de OrderEquipmentAttached precisa continuar
     * desserializando isso (api#108/CLAUDE.md), dividindo o texto em várias entradas. O PUT no
     * final exercita `OrderAggregate::retrieve()` relendo esse stream antigo (a lição do api#108 é
     * que o sintoma some na LEITURA e só aparece ao reler o stream).
     */
    public function test_replaying_a_legacy_string_accessories_payload_splits_it_into_entries(): void
    {
        $clientId = $this->aClientId();
        $user = $this->authenticatedUser();
        $order = app(OpenOrder::class)(
            1337, '2026-09-25', $clientId, $user->id,
            false, false, false, false, false, null, null, null, null, null, null, 100.0,
        );

        DB::table('stored_events')->insert([
            'aggregate_uuid' => $order->id,
            'aggregate_version' => 2,
            'event_version' => 1,
            'event_class' => OrderEquipmentAttached::class,
            // Formato pré-mudança: accessories como texto livre, sem orderEquipmentId.
            'event_properties' => json_encode([
                'equipmentId' => null,
                'name' => 'Bisturi',
                'brand' => null,
                'model' => null,
                'serialNumber' => null,
                'assetTag' => null,
                'accessories' => 'Cabo de força; pedal, , manual',
            ]),
            'meta_data' => json_encode(['aggregate-root-uuid' => $order->id, 'aggregate-root-version' => 2]),
            'created_at' => now(),
        ]);

        Projectionist::replay(collect([app(OrderProjector::class)]));

        $orderEquipmentId = DB::table('order_equipments')->where('order_id', $order->id)->value('id');
        $names = DB::table('order_equipment_accessories')
            ->where('order_equipment_id', $orderEquipmentId)
            ->orderBy('position')
            ->pluck('name');

        $this->assertSame(['Cabo de força', 'pedal', 'manual'], $names->all());
        $this->assertSame(3, DB::table('order_equipment_accessories')->where('quantity', 1)->count());

        // Exercita retrieve() no stream antigo — não só a leitura da projeção.
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/orders/{$order->id}", [
                'number' => 1337,
                'date' => '2026-09-25',
                'client_id' => $clientId,
                'labor_cost' => 100.0,
                'equipments' => [['name' => 'Bisturi', 'accessories' => []]],
                'items' => [],
            ]);

        $response->assertOk();
    }

    /**
     * O GET /equipments/{id} do fluxo de QR Code (api#115) torna trivial descobrir um uuid de
     * equipamento sem saber de qual cliente ele é — resolveEquipments() precisa confirmar que o
     * equipment_id pertence ao client_id da própria OS, não só que existe.
     */
    public function test_rejects_an_equipment_id_that_belongs_to_another_client(): void
    {
        $user = $this->authenticatedUser();
        $clientA = $this->aClientId();
        $clientB = $this->aClientId('11222333000181');
        $equipmentOfClientB = $this->anEquipmentId($clientB, 'Monitor do Cliente B');

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientA,
            'labor_cost' => 100.0,
            'equipments' => [['equipment_id' => $equipmentOfClientB]],
            'items' => [],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(404);
    }

    public function test_rejects_creation_without_any_value_in_items_or_labor_cost(): void
    {
        $clientId = $this->aClientId();

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'equipments' => [['name' => 'Bisturi']],
            'items' => [['quantity' => 1, 'description' => 'Peça sem valor', 'unit_price' => null]],
        ];

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('labor_cost');
    }

    public function test_rejects_creation_without_any_equipment(): void
    {
        $clientId = $this->aClientId();
        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [];

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('equipments');
    }

    public function test_rejects_creation_for_an_unknown_client(): void
    {
        $payload = $this->minimalOrderPayload((string) Str::uuid());

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('client_id');
    }

    public function test_rejects_creation_with_a_duplicate_number(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->openOrder(1337, $clientId, $user->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1337));

        $response->assertStatus(409);
    }

    public function test_shows_an_order(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId))
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$created['id']}");

        $response->assertOk();
        $response->assertJsonPath('data.number', 1337);
    }

    public function test_returns_404_for_an_unknown_order(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/orders/'.Str::uuid())
            ->assertStatus(404);
    }

    public function test_lists_orders_ordered_by_date_descending(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1337),
            'date' => '2026-09-01',
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1338),
            'date' => '2026-09-10',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders');

        $response->assertOk();
        $response->assertJsonPath('data.0.number', 1338);
        $response->assertJsonPath('data.1.number', 1337);
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    /**
     * Achado com uma OS cancelada aparecendo ACIMA de outras ativas da mesma data — a ordem
     * entre elas vinha indefinida do banco, só `orderBy('date', 'desc')` sem desempate. Uma
     * cancelada, criada PRIMEIRO (número mais baixo, deveria vir "antes" num desempate ingênuo por
     * criação), ainda assim tem que cair pro final da lista pela regra de status.
     */
    public function test_lists_canceled_orders_last_even_when_created_first(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $canceled = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1337),
            'date' => '2026-09-25',
        ])->json('data');
        app(ChangeOrderStatus::class)($canceled['id'], OrderStatus::Canceled);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1338),
            'date' => '2026-09-25',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders');

        $response->assertOk();
        $response->assertJsonPath('data.0.number', 1338);
        $response->assertJsonPath('data.0.status', 'open');
        $response->assertJsonPath('data.1.number', 1337);
        $response->assertJsonPath('data.1.status', 'canceled');
    }

    public function test_lists_orders_filtered_by_client(): void
    {
        $user = $this->authenticatedUser();
        $clientA = $this->aClientId();
        $clientB = (string) Str::uuid();
        ClientAggregate::retrieve($clientB)
            ->register(
                personType: PersonType::Company,
                name: 'Clínica Vida',
                taxId: '11222333000181',
                tradeName: null, stateRegistration: null, requester: null, department: null,
                phone: null, email: null, address: null, city: null, state: null, postalCode: null,
            )
            ->persist();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientA, 1337));
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientB, 1338));

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders?client_id={$clientA}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.number', 1337);
    }

    public function test_lists_orders_filtered_by_equipment(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1337),
            'equipments' => [['equipment_id' => $equipmentId]],
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1338));

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders?equipment_id={$equipmentId}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.number', 1337);
    }

    public function test_lists_orders_filtered_by_status_and_date_range(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1337),
            'date' => '2026-01-15',
        ]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId, 1338),
            'date' => '2026-09-15',
        ]);

        $byStatus = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders?status=open');
        $byStatus->assertJsonCount(2, 'data');

        $byDate = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/orders?date_from=2026-09-01&date_to=2026-09-30');
        $byDate->assertJsonCount(1, 'data');
        $byDate->assertJsonPath('data.0.number', 1338);
    }

    public function test_updates_an_order_replacing_equipments_and_items(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $oldEquipmentId = $this->anEquipmentId($clientId, 'Bisturi Antigo');

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId),
            'equipments' => [['equipment_id' => $oldEquipmentId]],
            'items' => [['quantity' => 1, 'description' => 'Peça antiga', 'unit_price' => 10.0]],
        ])->json('data');

        $newEquipmentId = $this->anEquipmentId($clientId, 'Monitor Novo');
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 200.0,
            'equipments' => [['equipment_id' => $newEquipmentId]],
            'items' => [['quantity' => 3, 'description' => 'Peça nova', 'unit_price' => 10.0]],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.equipments');
        $response->assertJsonPath('data.equipments.0.equipment_id', $newEquipmentId);
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.description', 'Peça nova');
        // total = 200 (labor_cost novo) + 3 * 10 (item novo) = 230 — o antigo não entra mais.
        $response->assertJsonPath('data.total', '230.00');

        $this->assertDatabaseMissing('order_equipments', ['order_id' => $created['id'], 'equipment_id' => $oldEquipmentId]);
        $this->assertDatabaseHas('stored_events', ['aggregate_uuid' => $created['id'], 'event_class' => OrderEquipmentsCleared::class]);
        $this->assertDatabaseHas('stored_events', ['aggregate_uuid' => $created['id'], 'event_class' => OrderItemsCleared::class]);
    }

    /**
     * PUT /orders/{id} não tinha trava de status nenhuma até esta issue — uma OS já
     * cancelada/concluída/reprovada podia ser editada normalmente.
     */
    public function test_rejects_updating_an_order_with_a_status_that_cannot_be_edited(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId, 'Bisturi');

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId),
            'equipments' => [['equipment_id' => $equipmentId]],
        ])->json('data');

        app(ChangeOrderStatus::class)($created['id'], OrderStatus::Canceled);

        $newEquipmentId = $this->anEquipmentId($clientId, 'Monitor Novo');
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            ...$this->minimalOrderPayload($clientId),
            'equipments' => [['equipment_id' => $newEquipmentId]],
        ]);

        $response->assertStatus(409);
        // Nada mudou: o equipamento original continua lá, o novo nunca foi anexado.
        $this->assertDatabaseHas('order_equipments', ['order_id' => $created['id'], 'equipment_id' => $equipmentId]);
        $this->assertDatabaseMissing('order_equipments', ['order_id' => $created['id'], 'equipment_id' => $newEquipmentId]);
    }

    public function test_updating_an_order_keeping_its_own_number_is_not_a_duplicate(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1337))
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            ...$this->minimalOrderPayload($clientId, 1337),
            'notes' => 'Observação atualizada',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.notes', 'Observação atualizada');
    }

    public function test_rejects_updating_an_order_with_a_number_used_by_another_order(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->openOrder(1337, $clientId, $user->id);
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1338))
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/orders/{$created['id']}", $this->minimalOrderPayload($clientId, 1337));

        $response->assertStatus(409);
    }

    public function test_returns_404_when_updating_an_unknown_order(): void
    {
        $clientId = $this->aClientId();

        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson('/api/v1/orders/'.Str::uuid(), $this->minimalOrderPayload($clientId))
            ->assertStatus(404);
    }

    public function test_changes_the_order_status(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId))
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'in_analysis']);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'in_analysis');
    }

    public function test_rejects_an_invalid_status_transition(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId))
            ->json('data');

        // open → completed não está na tabela de transições (api-conventions.md § Status da OS).
        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'completed']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['status']]);
    }

    public function test_returns_404_when_changing_the_status_of_an_unknown_order(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->patchJson('/api/v1/orders/'.Str::uuid().'/status', ['status' => 'in_analysis'])
            ->assertStatus(404);
    }

    public function test_completing_one_of_several_equipments_moves_the_order_to_partially_completed(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [
            ['name' => 'Monitor'],
            ['name' => 'Bomba de infusão'],
            ['name' => 'Mesa cirúrgica'],
        ];
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload)->json('data');
        $this->approveOrder($user, $created['id']);

        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$created['equipments'][0]['id']], 'situation' => 'completed'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'partially_completed');
        $response->assertJsonPath('data.equipments.0.situation', 'completed');
        $response->assertJsonPath('data.equipments.1.situation', 'in_analysis');
    }

    public function test_completing_the_last_pending_equipment_completes_the_order(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [['name' => 'Monitor'], ['name' => 'Bomba de infusão']];
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload)->json('data');
        $this->approveOrder($user, $created['id']);
        $ids = array_column($created['equipments'], 'id');

        $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$ids[0]], 'situation' => 'completed'],
        )->assertJsonPath('data.status', 'partially_completed');

        // Devolvido sem reparo também conta como resolvido — não precisa ser "completed" nos dois.
        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$ids[1]], 'situation' => 'returned_unrepaired'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_batch_changing_situation_updates_several_equipments_at_once(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [['name' => 'Monitor'], ['name' => 'Bomba de infusão'], ['name' => 'Mesa cirúrgica']];
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload)->json('data');
        $ids = array_column($created['equipments'], 'id');

        // Sem aprovar a OS — a situação do equipamento é independente do status da OS (api#140),
        // só a derivação de status é que só age em approved/partially_completed/warranty_repair.
        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$ids[0], $ids[1]], 'situation' => 'awaiting_part'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.equipments.0.situation', 'awaiting_part');
        $response->assertJsonPath('data.equipments.1.situation', 'awaiting_part');
        $response->assertJsonPath('data.equipments.2.situation', 'in_analysis');
    }

    public function test_manually_completing_an_order_with_a_pending_equipment_is_rejected(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [['name' => 'Monitor'], ['name' => 'Bomba de infusão']];
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload)->json('data');
        $this->approveOrder($user, $created['id']);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'completed']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['status']]);
    }

    /**
     * `partially_completed` só é alcançável pela derivação automática (api#140) — uma tentativa
     * manual de PATCH /status pra ele é rejeitada mesmo estando na tabela de transições
     * (OrderStatus::isAutomaticOnlyTransition()).
     */
    public function test_manually_moving_to_partially_completed_is_rejected(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId))
            ->json('data');
        $this->approveOrder($user, $created['id']);

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'partially_completed']);

        $response->assertStatus(422);
    }

    public function test_changing_equipment_situation_on_a_canceled_order_is_rejected(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId))
            ->json('data');
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'canceled'])
            ->assertOk();

        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$created['equipments'][0]['id']], 'situation' => 'completed'],
        );

        $response->assertStatus(409);
    }

    public function test_changing_situation_with_an_equipment_id_from_another_order_is_rejected(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $orderA = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1401))
            ->json('data');
        $orderB = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1402))
            ->json('data');

        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$orderA['id']}/equipments/situation",
            ['order_equipment_ids' => [$orderB['equipments'][0]['id']], 'situation' => 'completed'],
        );

        $response->assertStatus(422);
    }

    /**
     * Cenário do #140: reabrir em garantia volta só o equipamento com retrabalho — os outros
     * continuam completed, e a OS só volta pra completed quando ele também resolver de novo.
     */
    public function test_reopening_one_equipment_under_warranty_keeps_the_order_in_warranty_repair_until_it_completes_again(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();

        $payload = $this->minimalOrderPayload($clientId);
        $payload['equipments'] = [['name' => 'Monitor'], ['name' => 'Bomba de infusão']];
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload)->json('data');
        $ids = array_column($created['equipments'], 'id');
        $this->approveOrder($user, $created['id']);

        $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => $ids, 'situation' => 'completed'],
        )->assertJsonPath('data.status', 'completed');

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/orders/{$created['id']}/status", ['status' => 'warranty_repair'])
            ->assertJsonPath('data.status', 'warranty_repair');

        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$ids[0]], 'situation' => 'in_analysis'],
        );
        $response->assertOk();
        // Não volta sozinho pra approved/partially_completed — warranty_repair com pendente
        // fica parado até o retrabalho resolver.
        $response->assertJsonPath('data.status', 'warranty_repair');
        $response->assertJsonPath('data.equipments.0.situation', 'in_analysis');
        $response->assertJsonPath('data.equipments.1.situation', 'completed');

        $response = $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$ids[0]], 'situation' => 'completed'],
        );
        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
    }

    /**
     * `UpdateOrder` limpa e reanexa os equipamentos (clearEquipments()) — a situação e a data de
     * conclusão precisam sobreviver, casando por equipment_id (api#140), e a posição continua
     * seguindo a ordem do payload novo.
     */
    public function test_editing_an_order_preserves_equipment_situation_completed_at_and_position(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $monitorId = $this->anEquipmentId($clientId, 'Monitor');
        $pumpId = $this->anEquipmentId($clientId, 'Bomba de infusão');

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId),
            'equipments' => [['equipment_id' => $monitorId], ['equipment_id' => $pumpId]],
        ])->json('data');

        $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$created['equipments'][0]['id']], 'situation' => 'completed'],
        )->assertOk();

        $before = $this->actingAs($user, 'sanctum')->getJson("/api/v1/orders/{$created['id']}")->json('data');
        $completedAtBefore = $before['equipments'][0]['completed_at'];
        $this->assertNotNull($completedAtBefore);

        // Mesmos dois equipamentos, na mesma ordem, com equipment_id — o jeito que o frontend
        // reenvia ao editar (ver OrderEquipmentResource). A linha order_equipments é recriada do
        // zero (clearEquipments()+reattach), mas a situação não pode se perder.
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 150.0,
            'equipments' => [['equipment_id' => $monitorId], ['equipment_id' => $pumpId]],
            'items' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.equipments.0.situation', 'completed');
        $response->assertJsonPath('data.equipments.0.completed_at', $completedAtBefore);
        $response->assertJsonPath('data.equipments.0.position', 0);
        $response->assertJsonPath('data.equipments.1.situation', 'in_analysis');
        $response->assertJsonPath('data.equipments.1.position', 1);
    }

    /**
     * A derivação de status (api#140) tem que rodar de novo depois de editar — não só depois de
     * PATCH /equipments/situation. Remover o único equipamento ainda pendente numa edição precisa
     * completar a OS sozinha, mesmo sem nenhuma mudança de situação explícita nesta chamada.
     */
    public function test_editing_an_order_removing_the_last_pending_equipment_completes_it_automatically(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $monitorId = $this->anEquipmentId($clientId, 'Monitor');
        $pumpId = $this->anEquipmentId($clientId, 'Bomba de infusão');

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            ...$this->minimalOrderPayload($clientId),
            'equipments' => [['equipment_id' => $monitorId], ['equipment_id' => $pumpId]],
        ])->json('data');
        $this->approveOrder($user, $created['id']);

        $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/orders/{$created['id']}/equipments/situation",
            ['order_equipment_ids' => [$created['equipments'][0]['id']], 'situation' => 'completed'],
        )->assertJsonPath('data.status', 'partially_completed');

        // Edita removendo a bomba (ainda pendente) — só sobra o monitor, já concluído.
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/orders/{$created['id']}", [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 150.0,
            'equipments' => [['equipment_id' => $monitorId]],
            'items' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_second_identical_listing_is_served_from_cache_without_hitting_the_database(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders')->assertOk();

        DB::enableQueryLog();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders');
        $response->assertOk();
        // Confere o conteúdo, não só o status — ver o mesmo comentário em ClientsHttpTest.
        $response->assertJsonPath('data.0.client.id', $clientId);

        $this->assertEmpty(DB::getQueryLog(), 'A segunda chamada idêntica não deveria consultar o banco — deveria vir do cache.');
    }

    public function test_opening_a_new_order_invalidates_the_listing_cache(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1337));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders')->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $this->minimalOrderPayload($clientId, 1338));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/orders')->assertJsonCount(2, 'data');
    }
}
