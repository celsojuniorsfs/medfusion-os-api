<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Clients\Domain\ClientAggregate;
use Modules\Clients\Domain\Enums\PersonType;
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

    public function test_guests_cannot_access_client_endpoints(): void
    {
        $this->getJson('/api/v1/clients')->assertStatus(401);
    }

    public function test_lists_clients_with_search(): void
    {
        $this->aClientId('Hospital São Lucas', '31233218000110');
        $this->aClientId('Clínica Vida', '11222333000181');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients?search=Vida');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Clínica Vida');
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_lists_clients_searching_by_tax_id_with_punctuation(): void
    {
        // tax_id é gravado só com dígitos — buscar como o usuário vê na tela (com pontuação)
        // precisa achar mesmo assim.
        $this->aClientId('Hospital São Lucas', '31233218000110');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients?search='.urlencode('31.233.218/0001-10'));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Hospital São Lucas');
    }

    public function test_lists_clients_ordered_by_most_recent_first(): void
    {
        // Viaja no tempo entre os dois cadastros pra garantir created_at diferente de forma
        // determinística — o id é um uuid do agregado, não é sequencial, então não dá pra
        // desempatar por ele.
        $this->aClientId('Hospital São Lucas', '31233218000110');
        $this->travel(1)->minute();
        $this->aClientId('Clínica Vida', '11222333000181');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/clients');

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'Clínica Vida');
        $response->assertJsonPath('data.1.name', 'Hospital São Lucas');
    }

    public function test_creates_a_company_client_with_all_fields(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas',
                'trade_name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-10',
                'state_registration' => 'ISENTO',
                'requester' => 'Marcos',
                'department' => 'Manutenção',
                'phone' => '(17) 99999-9999',
                'email' => 'contato@saolucas.example',
                'address' => 'Rua das Flores, 100',
                'city' => 'São Paulo',
                'state' => 'SP',
                'postal_code' => '15775-000',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Hospital São Lucas');
        $response->assertJsonPath('data.tax_id', '31233218000110');
        $response->assertJsonPath('data.postal_code', '15775000');
        $this->assertDatabaseHas('clients', [
            'person_type' => 'company',
            'name' => 'Hospital São Lucas',
            'tax_id' => '31233218000110',
            'postal_code' => '15775000',
            'state' => 'SP',
        ]);
    }

    public function test_creates_an_individual_client_with_only_the_required_fields(): void
    {
        // Parte dos clientes cadastra em nome próprio (pessoa física), não com CNPJ — feedback
        // do Augusto em 10/09/2026.
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'individual',
                'name' => 'João da Silva',
                'tax_id' => '111.444.777-35',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'João da Silva');
        $this->assertDatabaseHas('clients', [
            'person_type' => 'individual',
            'name' => 'João da Silva',
            'tax_id' => '11144477735',
        ]);
    }

    public function test_rejects_creation_with_missing_required_field(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', ['tax_id' => '31.233.218/0001-10']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['person_type', 'name']);
    }

    public function test_rejects_creation_with_invalid_person_type(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'ong', // não existe na enum PersonType
                'name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-10',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('person_type');
    }

    public function test_rejects_creation_with_invalid_cnpj_checksum(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-11', // dígito verificador errado
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_rejects_creation_with_invalid_cpf_checksum(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'individual',
                'name' => 'João da Silva',
                'tax_id' => '111.444.777-36', // dígito verificador errado
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_rejects_a_valid_cnpj_when_person_type_is_individual(): void
    {
        // O documento tem que bater com o tipo declarado — não basta ter dígito verificador
        // correto de "algum" documento.
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'individual',
                'name' => 'João da Silva',
                'tax_id' => '31.233.218/0001-10', // CNPJ válido, mas person_type é individual
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_rejects_creation_with_duplicate_tax_id(): void
    {
        $this->aClientId('Hospital São Lucas', '31233218000110');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas — Filial',
                'tax_id' => '31.233.218/0001-10', // mesmo CNPJ, com pontuação diferente na grafia
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tax_id');
    }

    public function test_rejects_creation_with_invalid_state(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-10',
                'state' => 'XX', // não é uma UF
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('state');
    }

    public function test_rejects_creation_with_invalid_email(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->postJson('/api/v1/clients', [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas',
                'tax_id' => '31.233.218/0001-10',
                'email' => 'não-é-um-email',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
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
                'person_type' => 'company',
                'name' => 'Hospital São Lucas — Unidade Centro',
                'tax_id' => '31.233.218/0001-10',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Hospital São Lucas — Unidade Centro');
    }

    public function test_updating_a_client_keeping_its_own_tax_id_is_not_a_duplicate(): void
    {
        $id = $this->aClientId('Hospital São Lucas', '31233218000110');

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->putJson("/api/v1/clients/{$id}", [
                'person_type' => 'company',
                'name' => 'Hospital São Lucas — Unidade Centro',
                'tax_id' => '31.233.218/0001-10', // o mesmo tax_id do próprio cliente
            ]);

        $response->assertOk();
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
