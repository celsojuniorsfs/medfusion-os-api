<?php

namespace Modules\Alerts\Providers;

use Modules\Alerts\Presentation\Console\Commands\CheckEquipmentRevisionsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Migrations são descobertas automaticamente pelo pacote (auto-discover.migrations em
 * config/modules.php). Comandos artisan não: o namespace do módulo (Modules\Alerts\...) não bate
 * com a convenção que o Laravel auto-descobre. Sem Projectionist — módulo não é event-sourced
 * (mesmo espírito das tabelas de idempotência de #135/#147: registro interno, não projeção).
 */
class AlertsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Alerts';

    protected string $nameLower = 'alerts';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->commands([
            CheckEquipmentRevisionsCommand::class,
        ]);
    }
}
