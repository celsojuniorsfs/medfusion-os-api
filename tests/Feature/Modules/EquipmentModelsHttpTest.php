<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\EquipmentModels\Domain\EquipmentModelAggregate;
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
