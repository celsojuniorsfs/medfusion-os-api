<?php

namespace App\Modules\Identity;

use Illuminate\Support\ServiceProvider;

class IdentityServiceProvider extends ServiceProvider
{
    /**
     * As rotas do módulo (Presentation/routes.php) são carregadas por routes/api.php — não
     * aqui — para herdar o grupo de middleware "api" e o apiPrefix definidos em
     * bootstrap/app.php (withRouting). Um Service Provider por módulo continua existindo como
     * ponto de extensão (policies, bindings específicos do módulo).
     *
     * Projectors/Reactors são descobertos automaticamente pelo spatie/laravel-event-sourcing
     * (varre app/ inteiro — ver config/event-sourcing.php e docs/architecture.md).
     */
    public function boot(): void {}
}
