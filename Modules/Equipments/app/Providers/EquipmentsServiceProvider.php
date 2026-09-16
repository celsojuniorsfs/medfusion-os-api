<?php

namespace Modules\Equipments\Providers;

use Modules\Equipments\Infrastructure\Projectors\EquipmentProjector;
use Modules\Equipments\Presentation\Console\BackfillEquipmentModels;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Migrations são descobertas automaticamente pelo pacote (auto-discover.migrations em
 * config/modules.php). Projectors/Reactors e comandos de console NÃO são auto-descobertos (ver
 * config/event-sourcing.php) — cada módulo registra os seus aqui.
 */
class EquipmentsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Equipments';

    protected string $nameLower = 'equipments';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(EquipmentProjector::class);

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillEquipmentModels::class]);
        }
    }
}
