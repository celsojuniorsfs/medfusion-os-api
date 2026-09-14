<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accessories\Domain\AccessoryAggregate;
use Modules\Accessories\Infrastructure\ReadModels\Accessory;
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
