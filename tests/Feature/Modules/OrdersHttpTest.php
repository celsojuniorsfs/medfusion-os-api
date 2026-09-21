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
use Modules\Orders\Application\OpenOrder;
use Modules\Orders\Domain\Events\OrderEquipmentsCleared;
use Modules\Orders\Domain\Events\OrderItemsCleared;
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

    private function aClientId(): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: 'Hospital São Lucas',
                taxId: '31233218000110',
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
     * Trava o contrato documentado em `docs/openapi.yaml::OrderEquipmentInput` (corrigido em
     * 21/09/2026, ver o commit): `accessories` é texto livre e vale junto com `equipment_id` — é
     * o snapshot desta OS, independente do catálogo estruturado de acessórios do equipamento.
     */
    public function test_keeps_the_free_text_accessories_when_referencing_an_existing_equipment(): void
    {
        $user = $this->authenticatedUser();
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId, 'Monitor Cardíaco');

        $payload = [
            'number' => 1337,
            'date' => '2026-09-13',
            'client_id' => $clientId,
            'labor_cost' => 100.0,
            'equipments' => [['equipment_id' => $equipmentId, 'accessories' => 'Cabo de força, pedal']],
            'items' => [],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.equipments.0.equipment_id', $equipmentId);
        $response->assertJsonPath('data.equipments.0.accessories', 'Cabo de força, pedal');
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
