<?php

namespace Modules\Identity\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Identity\Infrastructure\Projectors\UserProjector;
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

        // Achado do code review de 13/09/2026: POST /auth/login não tinha nenhum rate limit —
        // sem isso, um script podia tentar senhas sem limite contra qualquer e-mail. Chave por
        // e-mail+IP (não só IP) para não deixar um atacante rotacionar e-mails livremente nem
        // travar todo mundo atrás do mesmo NAT/proxy por causa de um único e-mail sob ataque.
        RateLimiter::for('login', fn ($request) => Limit::perMinute(5)->by(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        ));
    }
}
