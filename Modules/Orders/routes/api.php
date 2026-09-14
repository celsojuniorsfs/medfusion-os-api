<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Presentation\Http\Controllers\OrderController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);

    // Antes de qualquer /orders/{id}, pra "next-number" não ser interpretado como um {id}.
    Route::get('/orders/next-number', [OrderController::class, 'nextNumber']);

    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);
    Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus']);
});
