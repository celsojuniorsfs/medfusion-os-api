<?php

namespace App\Modules\Clients;

use Illuminate\Support\ServiceProvider;

class ClientsServiceProvider extends ServiceProvider
{
    /**
     * Sem rotas ainda — CRUD de clientes é issue de uma próxima sessão da F4. Projectors são
     * descobertos automaticamente (ver config/event-sourcing.php e docs/architecture.md).
     */
    public function boot(): void {}
}
