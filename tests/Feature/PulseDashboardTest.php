<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Identity\Domain\UserAggregate;
use Modules\Identity\Infrastructure\ReadModels\User;
use Tests\TestCase;

/**
 * Não há tela de login de sessão neste app (é API-only pro front Angular) — o dashboard do Pulse
 * usa HTTP Basic Auth contra a tabela users (config/pulse.php) e o gate 'viewPulse'
 * (Modules/Identity/app/Providers/IdentityServiceProvider.php), que em produção libera só e-mails
 * de PULSE_ALLOWED_EMAILS. APP_ENV=testing não é 'local', então a allowlist é exercitada de verdade.
 */
class PulseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function aUser(string $email = 'tecnica@medfusion.example'): User
    {
        $uuid = (string) Str::uuid();
        UserAggregate::retrieve($uuid)
            ->register('Ana Técnica', $email, Hash::make('segredo'))
            ->persist();

        return User::findOrFail($uuid);
    }

    public function test_guest_without_credentials_is_unauthorized(): void
    {
        $response = $this->get('/pulse');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_outside_allowlist_is_forbidden(): void
    {
        $user = $this->aUser();

        $response = $this->withBasicAuth($user->email, 'segredo')->get('/pulse');

        $response->assertStatus(403);
    }

    public function test_authenticated_user_in_allowlist_can_view_dashboard(): void
    {
        $user = $this->aUser();
        config(['pulse.allowed_emails' => [$user->email]]);

        $response = $this->withBasicAuth($user->email, 'segredo')->get('/pulse');

        $response->assertStatus(200);
    }
}
