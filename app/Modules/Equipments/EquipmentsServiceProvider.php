<?php

namespace App\Modules\Equipments;

use Illuminate\Support\ServiceProvider;

class EquipmentsServiceProvider extends ServiceProvider
{
    /**
     * Sem rotas ainda — CRUD de equipamentos é issue de uma próxima sessão da F4. Projectors são
     * descobertos automaticamente (ver config/event-sourcing.php e docs/architecture.md).
     */
    public function boot(): void {}
}
