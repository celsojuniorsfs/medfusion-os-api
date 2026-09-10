<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

class ClientsHttpTest extends TestCase
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

    private function aClientId(string $companyName = 'Hospital São Lucas', string $taxId = '31.233.218/0001-10'): string
    {
        $uuid = (string) Str::uuid();
        ClientAggregate::retrieve($uuid)
            ->register($companyName, $taxId, null, null, null, null, null, null)
            ->persist();

        return $uuid;
    }

    public function test_guests_cannot_access_client_endpoints(): void
    {
        $this->getJson('/api/v1/clients')->assertStatus(401);
    }

    public function test_lists_clients_with_search(): void
    {
        $this->aClientId('Hospital São Lucas', '31.233.218/0001-10');
        $this->aClientId('Clínica Vida', '11.222.333/0001-81');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients?search=Vida');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.company_name', 'Clínica Vida');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_creates_a_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'company_name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-10',
                'city' => 'São Paulo',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.company_name', 'Hospital São Lucas');
        $this->assertDatabaseHas('clients', ['company_name' => 'Hospital São Lucas', 'city' => 'São Paulo']);
    }

    public function test_rejects_creation_with_missing_required_field(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', ['tax_id' => '31.233.218/0001-10']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('company_name');
    }

    public function test_rejects_creation_with_invalid_cnpj_checksum(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'company_name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-11', // dígito verificador errado
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_creates_a_client_with_cpf(): void
    {
        // Parte dos clientes cadastra em nome próprio (pessoa física), não com CNPJ — feedback
        // do Augusto em 10/09/2026.
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'company_name' => 'João da Silva',
                'tax_id' => '111.444.777-35',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.company_name', 'João da Silva');
        $this->assertDatabaseHas('clients', ['company_name' => 'João da Silva']);
    }

    public function test_rejects_creation_with_invalid_cpf_checksum(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'company_name' => 'João da Silva',
                'tax_id' => '111.444.777-36', // dígito verificador errado
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_shows_a_client(): void
    {
        $id = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson("/api/v1/clients/{$id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $id);
    }

    public function test_returns_404_for_an_unknown_client(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients/'.Str::uuid());

        $response->assertStatus(404);
    }

    public function test_updates_a_client(): void
    {
        $id = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$id}", [
                'company_name' => 'Hospital São Lucas — Unidade Centro',
                'tax_id' => '31.233.218/0001-10',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.company_name', 'Hospital São Lucas — Unidade Centro');
    }

    public function test_removes_a_client(): void
    {
        $id = $this->aClientId();

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->deleteJson("/api/v1/clients/{$id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('clients', ['id' => $id]);
    }
}
