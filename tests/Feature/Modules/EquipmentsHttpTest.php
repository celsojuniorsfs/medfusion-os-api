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
use Tests\TestCase;

class EquipmentsHttpTest extends TestCase
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

    private function aClientId(string $name = 'Hospital São Lucas', string $taxId = '31233218000110'): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(
                personType: PersonType::Company,
                name: $name,
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

    private function anEquipmentId(string $clientId, string $name = 'Bisturi', ?string $serialNumber = 'SN-123'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentAggregate::retrieve($uuid)
            ->register($clientId, $name, 'Marca X', null, $serialNumber, null, null)
            ->persist();

        return $uuid;
    }

    public function test_guests_cannot_access_equipment_endpoints(): void
    {
        $clientId = $this->aClientId();

        $this->getJson("/api/v1/clients/{$clientId}/equipments")->assertStatus(401);
    }

    public function test_lists_only_the_equipments_of_the_given_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $this->anEquipmentId($clientA, 'Bisturi', 'SN-A1');
        $this->anEquipmentId($clientB, 'Monitor', 'SN-B1');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson("/api/v1/clients/{$clientA}/equipments");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Bisturi');
    }

    public function test_returns_404_when_listing_equipments_of_an_unknown_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients/'.Str::uuid().'/equipments');

        $response->assertStatus(404);
    }

    public function test_creates_an_equipment_with_all_fields(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'name' => 'Bisturi Elétrico',
                'brand' => 'Marca X',
                'model' => 'BX-2000',
                'serial_number' => 'SN-123',
                'asset_tag' => 'PAT-456',
                'accessories' => 'Cabo de força, pedal',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Bisturi Elétrico');
        $response->assertJsonPath('data.client_id', $clientId);
        $this->assertDatabaseHas('equipments', [
            'client_id' => $clientId,
            'name' => 'Bisturi Elétrico',
            'serial_number' => 'SN-123',
        ]);
    }

    public function test_creates_an_equipment_with_only_the_required_field(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", ['name' => 'Bisturi']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Bisturi');
    }

    public function test_returns_404_when_creating_an_equipment_for_an_unknown_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients/'.Str::uuid().'/equipments', ['name' => 'Bisturi']);

        $response->assertStatus(404);
    }

    public function test_rejects_creation_with_missing_required_field(): void
    {
        $clientId = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", ['brand' => 'Marca X']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_accepts_a_duplicate_serial_number_in_the_same_client(): void
    {
        // Decisão documentada no openapi.yaml: serial_number repetido no mesmo cliente não é
        // bloqueado pela API — o aviso ao técnico é responsabilidade do frontend. Trava esse
        // comportamento pra não virar regressão sem querer.
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId, 'Bisturi', 'SN-123');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'name' => 'Bisturi (unidade 2)',
                'serial_number' => 'SN-123',
            ]);

        $response->assertCreated();
    }

    public function test_updates_an_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}", [
                'name' => 'Bisturi Elétrico',
                'brand' => 'Marca Y',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Bisturi Elétrico');
        $response->assertJsonPath('data.brand', 'Marca Y');
    }

    public function test_returns_404_when_updating_an_equipment_from_another_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $equipmentId = $this->anEquipmentId($clientA);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$clientB}/equipments/{$equipmentId}", ['name' => 'Bisturi']);

        $response->assertStatus(404);
    }

    public function test_removes_an_equipment(): void
    {
        $clientId = $this->aClientId();
        $equipmentId = $this->anEquipmentId($clientId);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientId}/equipments/{$equipmentId}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('equipments', ['id' => $equipmentId]);
    }

    public function test_returns_404_when_removing_an_equipment_from_another_client(): void
    {
        $clientA = $this->aClientId('Hospital São Lucas', '31233218000110');
        $clientB = $this->aClientId('Clínica Vida', '11222333000181');
        $equipmentId = $this->anEquipmentId($clientA);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/clients/{$clientB}/equipments/{$equipmentId}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('equipments', ['id' => $equipmentId]);
    }

    public function test_second_identical_listing_is_served_from_cache_without_hitting_the_database(): void
    {
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/clients/{$clientId}/equipments")->assertOk();

        DB::enableQueryLog();
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/clients/{$clientId}/equipments");
        $response->assertOk();

        // Client::findOrFail($id) roda em toda chamada (checa se o cliente ainda existe) — só a
        // consulta na tabela equipments precisa vir do cache.
        $queriedEquipments = collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'equipments'));
        $this->assertFalse($queriedEquipments, 'A segunda chamada idêntica não deveria consultar "equipments" — deveria vir do cache.');
    }

    public function test_registering_a_new_equipment_invalidates_the_listing_cache(): void
    {
        $clientId = $this->aClientId();
        $this->anEquipmentId($clientId, 'Bisturi', 'SN-1');
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(1, 'data');

        $this->anEquipmentId($clientId, 'Monitor', 'SN-2');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/clients/{$clientId}/equipments")
            ->assertJsonCount(2, 'data');
    }
}
