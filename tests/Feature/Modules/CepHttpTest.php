<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

class CepHttpTest extends TestCase
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

    public function test_guests_cannot_access_the_cep_endpoint(): void
    {
        $this->getJson('/api/v1/cep/15775000')->assertStatus(401);
    }

    public function test_returns_the_address_for_a_known_cep(): void
    {
        Http::fake([
            'viacep.com.br/*' => Http::response([
                'cep' => '15775-000',
                'logradouro' => 'Rua das Flores',
                'localidade' => 'Santa Fé do Sul',
                'uf' => 'SP',
            ]),
        ]);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/cep/15775000');

        $response->assertOk();
        $response->assertJsonPath('logradouro', 'Rua das Flores');
        $response->assertJsonPath('uf', 'SP');
    }

    public function test_normalizes_a_cep_with_punctuation_before_calling_viacep(): void
    {
        Http::fake([
            'https://viacep.com.br/ws/15775000/json/' => Http::response(['logradouro' => 'Rua das Flores']),
        ]);

        $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/cep/15775-000')
            ->assertOk();

        Http::assertSentCount(1);
    }

    public function test_passes_through_an_unknown_cep_as_the_viacep_returns_it(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['erro' => true])]);

        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/cep/00000000');

        $response->assertOk();
        $response->assertExactJson(['erro' => true]);
    }

    public function test_rejects_a_cep_that_is_not_8_digits(): void
    {
        $response = $this->actingAs($this->authenticatedUser(), 'sanctum')
            ->getJson('/api/v1/cep/123');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cep');
    }

    public function test_second_lookup_of_the_same_cep_does_not_call_viacep_again(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['logradouro' => 'Rua das Flores'])]);
        $user = $this->authenticatedUser();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cep/15775000')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/cep/15775000')->assertOk();

        Http::assertSentCount(1);
    }
}
