<?php

namespace Modules\Accessories\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Só rotas de API — final prefix "api/v1", igual ao resto do projeto: "api" vem daqui, "v1"
 * vem do próprio routes/api.php do módulo.
 */
class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Accessories';

    public function map(): void
    {
        Route::middleware('api')->prefix('api')->group(module_path($this->name, '/routes/api.php'));
    }
}
