<?php

namespace Modules\Orders\Providers;

use Modules\Orders\Infrastructure\Projectors\OrderProjector;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Criar/listar/exibir OS (api #45/#46) além da sugestão de número (api #44) — atualizar/mudar
 * status (api #45, PR 2), PDF e notificação continuam issues à parte. Migrations são
 * descobertas automaticamente pelo pacote (auto-discover.migrations em config/modules.php).
 * Projectors/Reactors NÃO são auto-descobertos (ver config/event-sourcing.php) — cada módulo
 * registra os seus aqui.
 */
class OrdersServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Orders';

    protected string $nameLower = 'orders';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(OrderProjector::class);
    }
}
