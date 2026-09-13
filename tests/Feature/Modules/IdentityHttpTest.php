<?php

namespace Tests\Feature\Modules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

/**
 * Achado do code review de 13/09/2026: os 3 endpoints de auth (POST /auth/login, POST
 * /auth/logout, GET /auth/me) não tinham nenhum teste HTTP, só os de agregado
 * (IdentityAggregateTest). Cobre também o rate limit de login adicionado no mesmo review
 * (IdentityServiceProvider::boot(), limiter "login").
 */
class IdentityHttpTest extends TestCase
{
    use RefreshDatabase;

    private function aUser(string $email = 'ana@medfusion.example', string $password = 'segredo123'): User
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', $email, Hash::make($password))
            ->persist();

        return User::findOrFail($uuid);
    }

    public function test_logs_in_with_valid_credentials(): void
    {
        $this->aUser('ana@medfusion.example', 'segredo123');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@medfusion.example',
            'password' => 'segredo123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.email', 'ana@medfusion.example');
        $this->assertIsString($response->json('token'));
    }

    public function test_rejects_login_with_wrong_password(): void
    {
        $this->aUser('ana@medfusion.example', 'segredo123');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@medfusion.example',
            'password' => 'senha-errada',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Credenciais inválidas.');
    }

    public function test_rejects_login_for_an_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ninguem@medfusion.example',
            'password' => 'qualquer-coisa',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_login_with_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_throttles_repeated_login_attempts(): void
    {
        // Limiter "login": 5/min por e-mail+IP (ver IdentityServiceProvider::boot()). A 6ª
        // tentativa no mesmo minuto, mesmo e-mail, deve tomar 429 em vez de mais um 401.
        $this->aUser('ana@medfusion.example', 'segredo123');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'ana@medfusion.example',
                'password' => 'senha-errada',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@medfusion.example',
            'password' => 'senha-errada',
        ])->assertStatus(429);
    }

    public function test_guests_cannot_access_me_or_logout(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }

    public function test_shows_the_authenticated_user(): void
    {
        $user = $this->aUser();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
    }

    /**
     * Verificado via banco (não com uma segunda chamada HTTP reaproveitando o mesmo token de
     * antes) de propósito: Illuminate\Auth\RequestGuard::user() memoiza o usuário resolvido pra
     * sempre (ver framework), e o guard 'sanctum' fica cacheado no AuthManager pelo resto do
     * teste — uma segunda requisição autenticada por header (diferente de actingAs(), que
     * sobrescreve o usuário do guard direto) dentro do MESMO método de teste sempre "autentica"
     * de novo com o usuário da primeira chamada, não importa o token enviado. Não é um bug da
     * aplicação (cada request de verdade em produção tem seu próprio ciclo de vida, sem esse
     * cache entre requisições) — é uma particularidade do harness de teste do Laravel.
     */
    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->aUser();
        $tokenA = $user->createToken('web')->plainTextToken;
        $tokenBId = (int) explode('|', $user->createToken('web')->plainTextToken)[0];
        $tokenAId = (int) explode('|', $tokenA)[0];

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenAId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenBId]);
    }
}
