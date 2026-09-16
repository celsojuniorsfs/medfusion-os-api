<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Accessories\Domain\Events\AccessoryRemoved;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

class AccessoriesHttpTest extends TestCase
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

    private function anAccessoryId(string $name = 'Cabo de força'): string
    {
        $uuid = (string) Str::uuid();
        AccessoryAggregate::retrieve($uuid)->register($name)->persist();

        return $uuid;
    }

    private function aClientId(): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register(PersonType::Company, 'Hospital São Lucas', '31233218000110', null, null, null, null, null, null, null, null, null, null)
            ->persist();

        return $uuid;
    }

    private function anEquipmentModelId(string $name = 'Bisturi', ?string $brand = 'Marca X', ?string $model = 'Modelo X'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentModelAggregate::retrieve($uuid)->register($name, $brand, $model)->persist();

        return $uuid;
    }

    public function test_guests_cannot_access_accessory_endpoints(): void
    {
        $this->getJson('/api/v1/accessories')->assertStatus(401);
        $this->postJson('/api/v1/accessories', ['name' => 'Pedal'])->assertStatus(401);
    }

    public function test_lists_accessories_ordered_by_name(): void
    {
        $this->anAccessoryId('Pedal');
        $this->anAccessoryId('Cabo de força');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/accessories');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Cabo de força');
        $response->assertJsonPath('data.1.name', 'Pedal');
    }

    public function test_lists_an_empty_catalog(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/accessories');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_creates_an_accessory(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/accessories', ['name' => 'Cabo de força']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Cabo de força');
        $this->assertDatabaseHas('accessories', ['name' => 'Cabo de força']);
    }

    public function test_rejects_creation_without_a_name(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/accessories', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_updates_an_accessory(): void
    {
        $accessoryId = $this->anAccessoryId('Cabo de forsa');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/accessories/{$accessoryId}", ['name' => 'Cabo de força']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Cabo de força');
        $this->assertDatabaseHas('accessories', ['id' => $accessoryId, 'name' => 'Cabo de força']);
    }

    public function test_returns_404_when_updating_an_unknown_accessory(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson('/api/v1/accessories/'.Str::uuid(), ['name' => 'Pedal'])
            ->assertStatus(404);
    }

    public function test_removes_an_accessory_that_is_not_in_use(): void
    {
        $accessoryId = $this->anAccessoryId('Acessório de teste');

        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/accessories/{$accessoryId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('accessories', ['id' => $accessoryId]);
    }

    /**
     * Mesmo par de asserções do catálogo de modelos: o 409 é o comportamento visível, mas o teste
     * que trava a implementação é o de que NENHUM evento de remoção foi gravado — recusar pelo erro
     * de FK (depois do persist) deixaria um AccessoryRemoved no stored_events com a linha viva.
     */
    public function test_refuses_to_remove_an_accessory_in_use(): void
    {
        $clientId = $this->aClientId();
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'equipment_model_id' => $this->anEquipmentModelId(),
                'no_accessories' => false,
                'accessories' => [['name' => 'Cabo de força', 'quantity' => 1]],
            ])
            ->assertCreated();

        $accessoryId = Accessory::where('name', 'Cabo de força')->value('id');

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/accessories/{$accessoryId}");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Este acessório está em uso por equipamentos cadastrados e não pode ser removido.');
        $this->assertDatabaseHas('accessories', ['id' => $accessoryId]);
        $this->assertDatabaseMissing('stored_events', ['event_class' => AccessoryRemoved::class]);
    }

    public function test_accepts_a_duplicate_name(): void
    {
        // Decisão documentada no openapi.yaml: nome repetido não é bloqueado pela API — o
        // seletor do frontend evita duplicata na prática. Trava esse comportamento num teste,
        // mesmo padrão de test_accepts_a_duplicate_serial_number_in_the_same_client.
        $this->anAccessoryId('Pedal');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/accessories', ['name' => 'Pedal']);

        $response->assertCreated();
        $this->assertEquals(2, Accessory::where('name', 'Pedal')->count());
    }
}
