<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
use Modules\EquipmentModels\Domain\Events\EquipmentModelRemoved;
use Modules\EquipmentModels\Infrastructure\ReadModels\EquipmentModel;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

class EquipmentModelsHttpTest extends TestCase
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

    private function anEquipmentModelId(string $name = 'Ultrassom', ?string $brand = 'Sonopus', ?string $model = 'XYZ-100'): string
    {
        $uuid = (string) Str::uuid();
        EquipmentModelAggregate::retrieve($uuid)->register($name, $brand, $model)->persist();

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

    public function test_guests_cannot_access_equipment_model_endpoints(): void
    {
        $this->getJson('/api/v1/equipment-models')->assertStatus(401);
        $this->postJson('/api/v1/equipment-models', [
            'name' => 'Monitor',
            'brand' => 'Marca',
            'model' => 'M-1',
        ])->assertStatus(401);
    }

    public function test_lists_equipment_models_ordered_by_name(): void
    {
        $this->anEquipmentModelId('Ventilador pulmonar');
        $this->anEquipmentModelId('Eletrocardiógrafo');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/equipment-models');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.name', 'Eletrocardiógrafo');
        $response->assertJsonPath('data.1.name', 'Ventilador pulmonar');
    }

    public function test_lists_an_empty_catalog(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/equipment-models');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_creates_an_equipment_model(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/equipment-models', [
                'name' => 'Cardioversor',
                'brand' => 'Sonopus',
                'model' => 'CV-20',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Cardioversor');
        $response->assertJsonPath('data.brand', 'Sonopus');
        $response->assertJsonPath('data.model', 'CV-20');
        $this->assertDatabaseHas('equipment_models', ['name' => 'Cardioversor', 'model' => 'CV-20']);
    }

    public function test_rejects_creation_without_name_brand_or_model(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/equipment-models', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'brand', 'model']);
    }

    public function test_updates_an_equipment_model(): void
    {
        $modelId = $this->anEquipmentModelId('Utrassom', 'Sonopus', 'XYZ-100');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/equipment-models/{$modelId}", [
                'name' => 'Ultrassom',
                'brand' => 'Sonopus',
                'model' => 'XYZ-100',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Ultrassom');
        $this->assertDatabaseHas('equipment_models', ['id' => $modelId, 'name' => 'Ultrassom']);
    }

    public function test_returns_404_when_updating_an_unknown_equipment_model(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson('/api/v1/equipment-models/'.Str::uuid(), [
                'name' => 'Ultrassom',
                'brand' => 'Sonopus',
                'model' => 'XYZ-100',
            ])
            ->assertStatus(404);
    }

    public function test_removes_an_equipment_model_that_is_not_in_use(): void
    {
        $modelId = $this->anEquipmentModelId('Modelo de teste');

        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/equipment-models/{$modelId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('equipment_models', ['id' => $modelId]);
    }

    /**
     * O caso que motivou o api#109: o usuário removeu um equipamento de teste e o modelo dele ficou
     * pra sempre no seletor. Agora dá pra remover — mas só quando ninguém usa.
     *
     * A recusa vem da FK `equipments.equipment_model_id` (restrictOnDelete), traduzida em 409 pelo
     * controller: este módulo não pode consultar Equipments pra saber a resposta (ver CLAUDE.md §
     * grafo de dependências).
     */
    public function test_refuses_to_remove_an_equipment_model_in_use(): void
    {
        $clientId = $this->aClientId();
        $user = $this->authenticatedUser();
        $modelId = $this->anEquipmentModelId('Ultrassom', 'Sonopus', 'XYZ-100');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clients/{$clientId}/equipments", [
                'equipment_model_id' => $modelId,
                'no_accessories' => true,
            ])
            ->assertCreated();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/equipment-models/{$modelId}");

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Este modelo está em uso por equipamentos cadastrados e não pode ser removido.');
        $this->assertDatabaseHas('equipment_models', ['id' => $modelId]);

        // O teste que mata a primeira versão desta implementação: eu tinha escrito a recusa como
        // "tenta remover e traduz o erro de FK em 409". Só que persist() grava o evento ANTES de o
        // projector rodar e a FK estourar — o modelo continuava na tabela, mas com um
        // EquipmentModelRemoved gravado, então o agregado se achava removido e um replay apagaria
        // um modelo que a produção ainda tem. Recusar antes de chamar a Action é o que evita isso.
        $this->assertDatabaseMissing('stored_events', ['event_class' => EquipmentModelRemoved::class]);
    }

    public function test_accepts_a_duplicate_model(): void
    {
        // Mesma decisão já documentada pro nome de Accessory e pro serial_number de Equipment:
        // repetido não é bloqueado pela API — o seletor do frontend evita duplicata na prática
        // oferecendo o que já existe antes de deixar cadastrar um modelo novo.
        $this->anEquipmentModelId('Ultrassom', 'Sonopus', 'XYZ-100');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/equipment-models', [
                'name' => 'Ultrassom',
                'brand' => 'Sonopus',
                'model' => 'XYZ-100',
            ]);

        $response->assertCreated();
        $this->assertEquals(2, EquipmentModel::where('name', 'Ultrassom')->count());
    }
}
