<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Presentation\Http\Controllers\OrderController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Registrar antes de qualquer /orders/{id} (ainda não existe — vem no api #45), pra não
    // colidir com o parâmetro de rota quando esse dia chegar.
    Route::get('/orders/next-number', [OrderController::class, 'nextNumber']);
});
