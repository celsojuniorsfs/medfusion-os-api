<?php

namespace Modules\Equipments\Providers;

use Modules\Equipments\Infrastructure\Projectors\EquipmentProjector;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Sem rotas ainda — CRUD de equipamentos é issue de uma próxima sessão da F4. Migrations são
 * descobertas automaticamente pelo pacote (auto-discover.migrations em config/modules.php).
 * Projectors/Reactors NÃO são auto-descobertos (ver config/event-sourcing.php) — cada módulo
 * registra os seus aqui.
 */
class EquipmentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Equipments';

    protected string $nameLower = 'equipments';

    /**
     * @var string[]
     */
    protected array $providers = [];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(EquipmentProjector::class);
    }
}
