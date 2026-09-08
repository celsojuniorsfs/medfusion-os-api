<?php

namespace App\Modules\Orders;

use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    /**
     * Sem rotas ainda — CRUD de OS, PDF e notificação são issues de próximas sessões. Projectors
     * são descobertos automaticamente (ver config/event-sourcing.php e docs/architecture.md).
     */
    public function boot(): void {}
}
