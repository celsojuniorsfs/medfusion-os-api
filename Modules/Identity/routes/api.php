<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Presentation\Http\Controllers\AuthController;

// Carregadas pelo RouteServiceProvider do módulo, dentro de Route::middleware('api')->prefix('api')
// — o ->prefix('v1') abaixo fecha o "api/v1" usado no resto do projeto.

Route::prefix('v1')->group(function () {
    // throttle:login — limiter definido em IdentityServiceProvider::boot() (5/min por e-mail+IP).
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });
});
