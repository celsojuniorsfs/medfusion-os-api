<?php

namespace Modules\Clients\Providers;

use Modules\Clients\Infrastructure\Projectors\ClientProjector;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Migrations são descobertas automaticamente pelo pacote (auto-discover.migrations em
 * config/modules.php). Projectors/Reactors NÃO são auto-descobertos (ver
 * config/event-sourcing.php) — cada módulo registra os seus aqui.
 */
class ClientsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Clients';

    protected string $nameLower = 'clients';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(ClientProjector::class);
    }
}
