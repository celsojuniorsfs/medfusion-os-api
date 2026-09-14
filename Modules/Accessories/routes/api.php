<?php

use Illuminate\Support\Facades\Route;
use Modules\Accessories\Presentation\Http\Controllers\AccessoryController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/accessories', [AccessoryController::class, 'index']);
    Route::post('/accessories', [AccessoryController::class, 'store']);
});
