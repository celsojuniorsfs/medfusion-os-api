<?php

namespace Modules\EquipmentModels\Providers;

use Modules\EquipmentModels\Infrastructure\Projectors\EquipmentModelProjector;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Spatie\EventSourcing\Facades\Projectionist;

/**
 * Migrations são descobertas automaticamente pelo pacote (auto-discover.migrations em
 * config/modules.php). Projectors/Reactors NÃO são auto-descobertos (ver
 * config/event-sourcing.php) — cada módulo registra os seus aqui.
 */
class EquipmentModelsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'EquipmentModels';

    protected string $nameLower = 'equipmentmodels';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Projectionist::addProjector(EquipmentModelProjector::class);
    }
}
