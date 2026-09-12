<?php

namespace Modules\Identity\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Identity\Infrastructure\Projectors\UserProjector;
use Modules\Identity\Infrastructure\ReadModels\User;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Migrations são descobertas automaticamente pelo pacote (auto-discover.migrations em
 * config/modules.php). Projectors/Reactors NÃO são auto-descobertos (ver
 * config/event-sourcing.php) — cada módulo registra os seus aqui.
 */
class IdentityServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Identity';

    protected string $nameLower = 'identity';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(UserProjector::class);

        // Gate exigido pelo Laravel Pulse (config/pulse.php) para liberar o dashboard em /pulse.
        // Fica aqui, e não em app/Providers (que não existe neste projeto de propósito — ver
        // bootstrap/providers.php), porque é uma regra de autorização sobre User, e Identity é o
        // módulo dono do usuário e da autenticação. Sem conceito de papel/role na v1 (decisão da
        // F3), então em produção a liberação é por allowlist de e-mail via PULSE_ALLOWED_EMAILS.
        Gate::define('viewPulse', fn (User $user) => $this->app->environment('local')
            || in_array($user->email, config('pulse.allowed_emails'), true));
    }
}
